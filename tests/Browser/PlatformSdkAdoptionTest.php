<?php

use App\Models\Course;
use Database\Factories\AccountFactory;
use Illuminate\Support\Facades\Http;
use Oceanix\Platform\Models\Account;

it('adds an existing verified administrator and preserves platform company courses', function () {
    seedAccessCatalog();
    $person = adminUser();
    $account = AccountFactory::new()->platformAdmin()->create(['email' => $person->email]);
    $person->update(['account_id' => $account->id]);
    $company = currentCompany();
    $company->update(['name' => 'SDK Maritime']);
    Course::factory()->create(['title' => 'Preserved Offshore Safety']);
    $this->actingAs($person)->withSession(['locale' => 'en', 'platform_account_id' => $account->id]);
    Http::preventStrayRequests();
    Http::fake(['api.workos.com/user_management/users*' => Http::response([
        'object' => 'list', 'data' => [['object' => 'user', 'id' => 'user_browser_verified', 'email' => 'browser-admin@example.com', 'email_verified' => true]],
        'list_metadata' => ['before' => null, 'after' => null],
    ])]);

    $page = visit(route('platform.users', absolute: false))->resize(1440, 900)->assertSee('Platform administrators')->assertSee('Oceanix SDK')->screenshot(filename: 'direct-sdk-users-desktop');
    $page->fill('input[wire\\:model="administratorName"]', 'Browser Administrator')
        ->fill('input[wire\\:model="administratorEmail"]', 'browser-admin@example.com')
        ->press('Add administrator')->assertSee('No invitation was sent.')
        ->assertSee('browser-admin@example.com')->assertNoJavaScriptErrors()
        ->resize(375, 812)->assertSee('Add administrator')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1')->screenshot(filename: 'direct-sdk-users-mobile');
    $page->navigate(route('platform.companies', absolute: false))->assertSee('SDK Maritime')->click('SDK Maritime')
        ->assertSee('Preserved Offshore Safety')->assertSee('Company administrators')->assertSee('Make Shared')
        ->assertNoJavaScriptErrors()->screenshot(filename: 'direct-sdk-company-courses-mobile')->resize(1440, 900)->screenshot(filename: 'direct-sdk-company-courses-desktop');
    expect(Account::where('email', 'browser-admin@example.com')->sole()->is_platform_admin)->toBeTrue();
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

it('shows provider failure without granting platform access', function () {
    $person = adminUser();
    $account = AccountFactory::new()->platformAdmin()->create();
    $person->update(['account_id' => $account->id]);
    $this->actingAs($person)->withSession(['locale' => 'en', 'platform_account_id' => $account->id]);
    Http::preventStrayRequests();
    Http::fake(['api.workos.com/user_management/users*' => Http::response([], 503)]);
    visit(route('platform.users', absolute: false))->resize(1440, 900)
        ->fill('input[wire\\:model="administratorName"]', 'Unavailable Administrator')
        ->fill('input[wire\\:model="administratorEmail"]', 'unavailable@example.com')
        ->press('Add administrator')->assertSee('No access was granted and no invitation was sent.')
        ->assertNoJavaScriptErrors();
    expect(Account::where('email', 'unavailable@example.com')->exists())->toBeFalse();
});
