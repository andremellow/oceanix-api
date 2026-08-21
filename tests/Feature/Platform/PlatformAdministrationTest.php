<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Platform\PlatformOverview;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;

it('denies the platform area to a company administrator', function (): void {
    $this->actingAs(adminUser())
        ->get(route('platform.dashboard'))
        ->assertForbidden();
});

it('offers platform sign-in instead of forbidding an unauthenticated visitor', function (): void {
    auth()->logout();
    session()->forget('platform_account_id');

    $this->get(route('platform.dashboard'))
        ->assertRedirect(route('login', ['platform' => 1]));

    $this->get(route('login', ['platform' => 1]))
        ->assertOk()
        ->assertSee(__('Sign in as platform administrator'));
});

it('allows a platform administrator to view cross-company metrics', function (): void {
    Company::factory()->create();
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);

    $this->actingAs($user)
        ->get(route('platform.dashboard'))
        ->assertOk()
        ->assertSee(__('Global overview'))
        ->assertSee(__('Sign out'))
        ->assertSee(route('platform.logout'), escape: false);
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

    $company = Company::query()->where('slug', 'hydra-maritime')->firstOrFail();
    app(TenantContext::class)->set($company);

    expect(AuditLog::query()->where('action', 'platform.company_created')->exists())->toBeTrue();
});

it('shows only active people and open assignments in platform metrics', function (): void {
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);
    User::factory()->create(['status' => 'suspended']);
    UserTrainingAssignment::factory()->create(['status' => 'pending']);
    UserTrainingAssignment::factory()->completed()->create();

    $this->actingAs($user)
        ->get(route('platform.dashboard'))
        ->assertOk();

    $metrics = app(PlatformOverview::class)->metrics();

    expect($metrics['people'])->toBe(User::withoutGlobalScope('company')->where('status', 'active')->count())
        ->and($metrics['assignments'])->toBe(1);
});

it('allows a platform administrator to suspend and reactivate a company with an audit trail', function (): void {
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);
    $company = currentCompany();

    Livewire\Livewire::actingAs($user)
        ->test('platform.company', ['company' => $company])
        ->call('changeStatus', 'suspended')
        ->assertHasNoErrors();

    expect($company->fresh()->status)->toBe('suspended');
    app(TenantContext::class)->set($company);
    expect(AuditLog::query()->where('action', 'platform.company_status_changed')->count())->toBe(1);

    Livewire\Livewire::actingAs($user)
        ->test('platform.company', ['company' => $company])
        ->call('changeStatus', 'active')
        ->assertHasNoErrors();

    expect($company->fresh()->status)->toBe('active');
});

it('logs out the tenant guard when leaving the platform', function (): void {
    $user = adminUser();
    $account = Account::factory()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);

    $this->actingAs($user)
        ->post(route('platform.logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('redirects an old unscoped tenant URL to its company-scoped equivalent', function (): void {
    $user = adminUser();
    $company = currentCompany();

    $this->actingAs($user)
        ->withSession(['company_id' => $company->id])
        ->get('/dashboard')
        ->assertRedirect(route('dashboard', ['company' => $company]));
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

it('lets a platform administrator explicitly create access to an existing company', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_company']);
    $account = Account::factory()->platformAdmin()->create(['email' => 'platform-owner@example.com']);
    $this->withSession(['platform_account_id' => $account->id]);
    Http::fake([
        'api.workos.com/user_management/organization_memberships*' => Http::sequence()
            ->push(['data' => []])
            ->push(['id' => 'om_platform']),
    ]);

    Livewire\Livewire::test('platform.company', ['company' => $company])
        ->call('grantMyAccess')
        ->assertHasNoErrors();

    app(TenantContext::class)->set($company);
    $person = User::query()->where('account_id', $account->id)->firstOrFail();

    expect($person->hasRole('admin'))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'platform.company_admin_access_granted')->exists())->toBeTrue();
});

it('creates a membership instead of inviting an existing WorkOS user', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_company']);
    $platformAccount = Account::factory()->platformAdmin()->create();
    Account::factory()->create([
        'email' => 'existing-admin@example.com',
        'provider' => 'workos',
        'provider_id' => 'user_existing',
        'workos_user_id' => 'user_existing',
    ]);
    $this->withSession(['platform_account_id' => $platformAccount->id]);
    Http::fake([
        'api.workos.com/user_management/organization_memberships*' => Http::sequence()
            ->push(['data' => []])
            ->push(['id' => 'om_existing']),
    ]);

    Livewire\Livewire::test('platform.company', ['company' => $company])
        ->set('administratorName', 'Existing Administrator')
        ->set('administratorEmail', 'existing-admin@example.com')
        ->call('inviteAdministrator')
        ->assertHasNoErrors()
        ->assertSee(__('Existing WorkOS user added as a company administrator.'));

    app(TenantContext::class)->set($company);
    $person = User::query()->where('email', 'existing-admin@example.com')->firstOrFail();

    expect($person->hasRole('admin'))->toBeTrue()
        ->and($person->workos_invitation_id)->toBeNull();

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.workos.com/user_management/organization_memberships'
        && $request['user_id'] === 'user_existing'
        && $request['organization_id'] === 'org_company');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/invitations'));
});

it('lets a platform administrator invite another company administrator', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_company']);
    $account = Account::factory()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $account->id]);
    Http::fake([
        'api.workos.com/user_management/invitations' => Http::response(['id' => 'inv_admin'], 201),
    ]);

    Livewire\Livewire::test('platform.company', ['company' => $company])
        ->set('administratorName', 'Tenant Administrator')
        ->set('administratorEmail', 'tenant-admin@example.com')
        ->call('inviteAdministrator')
        ->assertHasNoErrors();

    app(TenantContext::class)->set($company);
    $person = User::query()->where('email', 'tenant-admin@example.com')->firstOrFail();

    expect($person->hasRole('admin'))->toBeTrue()
        ->and($person->workos_invitation_id)->toBe('inv_admin')
        ->and(AuditLog::query()->where('action', 'platform.company_administrator_granted')->exists())->toBeTrue();

    Http::assertSent(fn ($request): bool => $request['email'] === 'tenant-admin@example.com'
        && $request['organization_id'] === 'org_company');
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

    app(TenantContext::class)->set($company);
    expect(AuditLog::query()->where('action', 'platform.company_workos_provisioned')->exists())->toBeTrue();

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
