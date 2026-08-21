<?php

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;

it('denies the platform area to a company administrator', function (): void {
    $this->actingAs(adminUser())
        ->get(route('platform.dashboard'))
        ->assertForbidden();
});

it('allows a platform administrator to view cross-company metrics', function (): void {
    Company::factory()->create();
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);

    $this->actingAs($user)
        ->get(route('platform.dashboard'))
        ->assertOk()
        ->assertSee(__('Global overview'));
});

it('allows a platform administrator to create a company', function (): void {
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);

    $this->actingAs($user)
        ->get(route('platform.companies'))
        ->assertOk();

    Livewire\Livewire::actingAs($user)
        ->test('platform.companies')
        ->set('name', 'Hydra Maritime')
        ->set('slug', 'hydra-maritime')
        ->call('create')
        ->assertHasNoErrors();

    expect(Company::query()->where('slug', 'hydra-maritime')->exists())->toBeTrue();
});

it('creates the first company and its administrator from a session-only platform account', function (): void {
    Company::query()->delete();
    app(TenantContext::class)->clear();
    $account = Account::factory()->platformAdmin()->create(['email' => 'bootstrap@example.com']);
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.companies')
        ->set('name', 'First Company')
        ->set('slug', 'first-company')
        ->call('create')
        ->assertHasNoErrors();

    $company = Company::query()->where('slug', 'first-company')->firstOrFail();
    app(TenantContext::class)->set($company);
    $person = User::query()->where('account_id', $account->id)->firstOrFail();

    expect($person->hasRole('admin'))->toBeTrue();

    app(TenantContext::class)->clear();
    $this->post(route('platform.companies.enter', ['company' => $company]))
        ->assertRedirect(route('dashboard', ['company' => $company]));
    expect(auth()->id())->toBe($person->id);
});

it('provisions a company in WorkOS with its stable public id', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);
    $company = currentCompany();

    Http::fake([
        'api.workos.com/organizations/external_id/*' => Http::response([], 404),
        'api.workos.com/organizations' => Http::response([
            'id' => 'org_oceanix_demo',
            'name' => $company->name,
            'external_id' => $company->public_id,
        ], 201),
    ]);

    Livewire\Livewire::actingAs($user)
        ->test('platform.companies')
        ->call('provisionWorkos', $company->id)
        ->assertHasNoErrors();

    expect($company->fresh()->workos_organization_id)->toBe('org_oceanix_demo');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.workos.com/organizations'
        && $request['name'] === $company->name
        && $request['external_id'] === $company->public_id
        && $request->hasHeader('Authorization', 'Bearer sk_test'));
});

it('reconnects an existing WorkOS organization by external id', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);
    $company = currentCompany();

    Http::fake([
        'api.workos.com/organizations/external_id/*' => Http::response([
            'id' => 'org_existing',
            'external_id' => $company->public_id,
        ]),
    ]);

    Livewire\Livewire::actingAs($user)
        ->test('platform.companies')
        ->call('provisionWorkos', $company->id)
        ->assertHasNoErrors();

    expect($company->fresh()->workos_organization_id)->toBe('org_existing');
    Http::assertSentCount(1);
});
