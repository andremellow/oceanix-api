<?php

use App\Tenancy\TenantContext;
use Database\Factories\AccountFactory;
use Database\Factories\CompanyFactory;
use Illuminate\Support\Facades\Http;
use Oceanix\Platform\Models\Company;

it('SC-01 SC-02 preserves responsive company inspection and entry with Account ownership', function (int $width): void {
    Http::fake();
    $company = currentCompany();
    $company->update(['name' => str_repeat('Long Maritime Operations ', 6), 'workos_organization_id' => 'org_account_owned']);
    $unlinked = CompanyFactory::new()->create(['name' => 'Unlinked Maritime']);
    app(TenantContext::class)->set($company);
    $user = adminUser();
    $account = AccountFactory::new()->platformAdmin()->create(['email' => $user->email]);
    $user->update(['account_id' => $account->id]);
    $this->actingAs($user)->withSession(['platform_account_id' => $account->id, 'company_id' => $company->id]);

    $page = visit(route('platform.companies', absolute: false))->resize($width, 900)
        ->assertSee('Create companies and enable product access in Account.')
        ->assertSee($company->name)->assertSee($unlinked->name)
        ->assertNotPresent('[wire\\:submit="create"]')
        ->assertNotPresent('[wire\\:click^="provisionWorkos"]')
        ->assertNoJavaScriptErrors();
    expect($page->script('() => document.querySelector("main").scrollWidth <= document.querySelector("main").clientWidth'))->toBeTrue();

    $page->keys('main a[href="'.route('platform.companies.show', ['company' => $company]).'"]', 'Enter')
        ->assertSee('Back to companies')->assertSee('Company administrators')
        ->assertSee('Create companies and enable product access in Account.')
        ->assertNotPresent('[wire\\:click^="provisionWorkos"]')
        ->assertNoJavaScriptErrors();
    $page->click('Back to companies')->assertSee($unlinked->name)
        ->click('main form[action="'.route('platform.companies.enter', ['company' => $company]).'"] button')
        ->assertPathIs('/c/'.$company->slug.'/dashboard')->assertNoJavaScriptErrors();
    Http::assertNothingSent();
})->with([320, 1440]);

it('SC-01 displays a responsive informational empty company list', function (int $width): void {
    Http::fake();
    Company::query()->delete();
    app(TenantContext::class)->clear();
    $account = AccountFactory::new()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $account->id]);

    $page = visit(route('platform.companies', absolute: false))->resize($width, 900)
        ->assertSee('No companies')
        ->assertSee('Create companies and enable product access in Account.')
        ->assertNotPresent('[wire\\:submit="create"]')
        ->assertNotPresent('[wire\\:click^="provisionWorkos"]')
        ->assertNoJavaScriptErrors();
    expect($page->script('() => document.querySelector("main").scrollWidth <= document.querySelector("main").clientWidth'))->toBeTrue();
    expect(Company::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([320, 1440]);
