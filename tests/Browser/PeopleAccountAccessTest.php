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
    $sentEnvelope = null;
    Http::fake(['account.example.test/*' => function ($request) use (&$sentEnvelope, $person) {
        if ($request->method() === 'PUT') {
            $sentEnvelope = $request->data();

            return Http::failedConnection();
        }
        PersonProductAccess::where('user_id', $person->id)->update(['confirmed_enabled' => false, 'version' => $sentEnvelope['resource_sequence']]);

        return Http::response(array_intersect_key($sentEnvelope, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['enabled' => false]]);
    }]);
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
    $id = AccountRequestOperation::sole()->id;
    $page->press('Check access removal again')->press('Confirm removal')->assertSee('Product access removal confirmed.')->assertMissing('#account-product-remove')->assertNoJavaScriptErrors();
    expect(AccountRequestOperation::sole()->id)->toBe($id)->and(AccountRequestOperation::sole()->state)->toBe('confirmed')->and($person->fresh()->getRawOriginal())->toBe($before);
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});

it('offers accessible registration and same-request recovery without automatic invitation', function ($locale, $width) {
    seedAccessCatalog();
    config(['app.locale' => $locale]);
    app()->setLocale($locale);
    Http::preventStrayRequests();
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_browser_registration']);
    AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => $company->workos_organization_id]);
    $actor = userWithPermissions([Permission::PeopleManage]);
    $actor->forceFill(['workos_user_id' => 'user_browser_register_actor'])->save();
    $this->actingAs($actor);
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    $sentEnvelope = null;
    $mappingAttempts = 0;
    Http::fake(['account.example.test/*' => function ($request) use (&$sentEnvelope, &$mappingAttempts) {
        if (str_ends_with($request->url(), '/local-person')) {
            $mappingAttempts++;
            if ($mappingAttempts === 1) {
                return Http::failedConnection();
            }
            $input = $request->data();
            expect(PersonProductAccess::where('user_id', $input['payload']['local_person_id'])->sole()->confirmed_enabled)->toBeFalse();

            return Http::response(array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence', 'actor_workos_user_id'])) + ['outcome' => 'confirmed', 'confirmed_state' => $input['payload'] + ['mapping_complete' => true]]);
        }
        if ($request->method() === 'PUT') {
            $sentEnvelope = $request->data();

            return Http::failedConnection();
        }
        expect(User::where('email', 'browser-registration@example.test')->count())->toBe($mappingAttempts === 0 ? 0 : 1);

        return Http::response(array_intersect_key($sentEnvelope, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['shared_person_id' => '11111111-1111-4111-8111-111111111111', 'company_member_id' => '22222222-2222-4222-8222-222222222222', 'workos_user_id' => 'user_browser_registered', 'workos_membership_id' => 'om_browser_registered', 'membership_status' => 'pending', 'registration_mode' => 'confirmed-first', 'registration_source' => 'form', 'actor_workos_user_id' => $sentEnvelope['actor_workos_user_id'], 'request_payload_hash' => ApplyProductOperation::hash($sentEnvelope), 'mapping_complete' => false]]);
    }]);
    $page = visit(route('people.create', [], false))->resize($width, 844);
    $page->script(<<<'JS'
        window.originalRegistrationFetch=window.fetch.bind(window);window.heldRegistrations=0;
        window.fetch=(...args)=>{
            if(typeof args[1]?.body==='string' && args[1].body.includes('\"method\":\"save\"')){
                window.heldRegistrations++;
                return new Promise((resolve,reject)=>{window.releaseRegistration=()=>window.originalRegistrationFetch(...args).then(resolve,reject)});
            }return window.originalRegistrationFetch(...args);
        };void 0;
    JS);
    $page
        ->fill('#account-person-name', 'Long Offshore Registration Recipient With Pending Central Confirmation')
        ->fill('#account-person-email', 'browser-registration@example.test')->keys('#account-person-save', 'Enter')->assertDisabled('#account-person-save')->assertSee(__('Waiting for Account confirmation…'))->assertScript('window.heldRegistrations', 1);
    $page->script("document.getElementById('account-person-save').click()");
    $page->assertScript('window.heldRegistrations', 1)->script('void window.releaseRegistration()');
    $page
        ->assertSee(__('Account has not confirmed this registration. Retry checks the same request; no invitation will be sent.'))
        ->assertSee(__('Check registration again'))->assertDisabled('#account-person-name')->assertDisabled('#account-person-email')->assertNoJavaScriptErrors();
    expect(User::where('email', 'browser-registration@example.test')->count())->toBe(0)
        ->and(AccountRequestOperation::sole()->state)->toBe('unknown');
    $id = AccountRequestOperation::sole()->id;
    $page->script('window.fetch=window.originalRegistrationFetch;void 0');
    $page->press(__('Check registration again'))
        ->assertSee($locale === 'pt_BR' ? 'O Account confirmou esta pessoa. O acesso aguarda confirmação; tentar novamente consulta a mesma solicitação.' : 'Account confirmed this person. Access is pending confirmation; retry checks the same request.')
        ->assertScript('window.location.pathname', route('people.create', [], false))
        ->assertDontSee(__('Person registered. No invitation was sent.'))
        ->assertDisabled('#account-person-name')->assertDisabled('#account-person-email')->assertNoJavaScriptErrors();
    $pending = AccountRequestOperation::sole();
    $person = User::where('email', 'browser-registration@example.test')->sole();
    expect($pending->state)->toBe('mapping_pending')->and($pending->id)->toBe($id)->and($person->roles()->count())->toBe(0)->and(PersonProductAccess::where('user_id', $person->id)->sole()->confirmed_enabled)->toBeFalse();
    $page->press(__('Check registration again'))->assertSee(__('Person registered. No invitation was sent.'))->assertNoJavaScriptErrors();
    expect(AccountRequestOperation::sole()->user_id)->toBe($person->id);
    expect(AccountRequestOperation::sole()->id)->toBe($id)->and(AccountRequestOperation::sole()->state)->toBe('confirmed')->and(User::where('email', 'browser-registration@example.test')->count())->toBe(1);
    Http::assertSentCount(5);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
})->with(['English desktop' => ['en', 1440], 'Portuguese mobile' => ['pt_BR', 390]]);
