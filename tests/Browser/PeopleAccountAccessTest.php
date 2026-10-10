<?php

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Enums\Permission;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
use App\Models\PersonProductAccess;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AccountIntegrationFixture as Fixture;

it('shows pending Account outcomes without optimistic success or local status changes', function () {
    [$company, $person, $principal, $token, $envelope] = Fixture::receiver();
    app(ApplyProductOperation::class)->handle($principal, $envelope);
    $actor = userWithPermissions([Permission::PeopleManage]);
    $actor->forceFill(['workos_user_id' => 'user_browser_removal_actor'])->save();
    $this->actingAs($actor);
    $before = $person->fresh()->getRawOriginal();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    Http::fake(['account.example.test/*' => Http::failedConnection()]);
    $page = visit(route('people.show', ['user' => $person], false))->resize(390, 844);
    $page->assertSee('Product access is separate from local person status and recorded first access.')
        ->keys('#account-product-remove', 'Enter')->assertVisible('dialog[open]')
        ->assertScript("document.querySelector('dialog[open]').getAttribute('aria-labelledby')", 'account-product-removal-heading')
        ->press('dialog[open] button:has-text("Cancel")')->assertScript('document.activeElement.id', 'account-product-remove')
        ->press('#account-product-remove')->press('Confirm removal')
        ->assertSee('Account has not confirmed this change. Product access remains at its last confirmed state.')
        ->assertSee('Check access removal again')->assertNoJavaScriptErrors();
    expect(AccountRequestOperation::sole()->state)->toBe('unknown')
        ->and(PersonProductAccess::where('user_id', $person->id)->sole()->confirmed_enabled)->toBeTrue()
        ->and($person->fresh()->getRawOriginal())->toBe($before);
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});

it('offers accessible registration and same-request recovery without automatic invitation', function () {
    Http::preventStrayRequests();
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_browser_registration']);
    AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => $company->workos_organization_id]);
    $actor = userWithPermissions([Permission::PeopleManage]);
    $actor->forceFill(['workos_user_id' => 'user_browser_register_actor'])->save();
    $this->actingAs($actor);
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    Http::fake(['account.example.test/*' => Http::failedConnection()]);
    visit(route('people.create', [], false))->resize(390, 844)
        ->fill('#account-person-name', 'Long Offshore Registration Recipient With Pending Central Confirmation')
        ->fill('#account-person-email', 'browser-registration@example.test')->press('#account-person-save')
        ->assertSee('Account has not confirmed this registration. Retry checks the same request; no invitation will be sent.')
        ->assertSee('Check registration again')->assertDisabled('#account-person-name')->assertDisabled('#account-person-email')->assertNoJavaScriptErrors();
    expect(User::where('email', 'browser-registration@example.test')->count())->toBe(1)
        ->and(AccountRequestOperation::sole()->state)->toBe('unknown');
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});
