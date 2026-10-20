<?php

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Enums\Permission;
use App\Enums\WorkosInvitationState;
use App\Enums\WorkosOperationStatus;
use App\Jobs\ReconcileWorkosInvitations;
use App\Models\Account;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
use App\Models\PersonProductAccess;
use App\Models\User;
use App\Models\WorkosInvitationAttempt;
use App\Models\WorkosSyncRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

it('exercises invitation filters and recovery confirmation at mobile and desktop widths', function () {
    $this->actingAs(adminUser());
    currentCompany()->update(['workos_organization_id' => 'org_test']);
    $person = User::factory()->create(['name' => 'Long Offshore Invitation Recipient With Separate Access Evidence']);
    $person->forceFill(['invitation_state' => WorkosInvitationState::Pending])->save();
    Queue::fake();
    $page = visit(route('people.index', [], false))->resize(1440, 900)->assertSee('Invitation status')->assertSee('Open training')->assertSee('No recorded access')
        ->select('select[name="invitation_status"]', 'pending')->assertSee($person->name)
        ->select('select[name="invitation_status"]', 'accepted')->assertSee('No people match these filters')
        ->click('Clear filters')->click('button[wire\\:click="inviteAllPending"]')->assertSee('Confirm invitation recovery')
        ->keys('dialog[open] button[wire\\:click="queueInvitations"]', 'Escape')->assertMissing('dialog[open]')
        ->resize(320, 568)->assertSee('Synchronize WorkOS')->assertSee('Invitation status')->assertNoJavascriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(filename: 'people-invitation-mobile');
});

it('queues exactly the visible checkbox recipient after filter morphing', function () {
    $this->actingAs(adminUser());
    currentCompany()->update(['workos_organization_id' => 'org_test']);
    $accepted = User::factory()->create(['name' => 'Accepted Identity Control']);
    $accepted->forceFill(['invitation_state' => WorkosInvitationState::Accepted])->save();
    $pending = User::factory()->create(['name' => 'Pending Identity Recipient']);
    $pending->forceFill(['invitation_state' => WorkosInvitationState::Pending])->save();
    $expired = User::factory()->create(['name' => 'Expired Identity Recipient']);
    $expired->forceFill(['invitation_state' => WorkosInvitationState::Expired])->save();
    Queue::fake();
    $page = visit(route('people.index', [], false))->resize(320, 568)
        ->select('select[name="invitation_status"]', 'pending')
        ->click('[aria-label="Select Pending Identity Recipient"]')
        ->click('button[wire\\:click="inviteSelected"]')->assertSee('This action covers 1 selected people.')
        ->click('dialog[open] button[wire\\:click="queueInvitations"]')->assertMissing('dialog[open]')->assertSee('1 invitation queued');
    expect(WorkosInvitationAttempt::pluck('person_id')->all())->toBe([$pending->id]);
    $page->select('select[name="invitation_status"]', 'expired')
        ->click('[aria-label="Select Expired Identity Recipient"]')
        ->click('button[wire\\:click="inviteSelected"]')
        ->click('dialog[open] button[wire\\:click="queueInvitations"]')->assertMissing('dialog[open]')->assertSee('1 invitation queued')->assertNoJavascriptErrors();
    expect(WorkosInvitationAttempt::orderBy('id')->pluck('person_id')->all())->toBe([$pending->id, $expired->id]);
});

it('uses cancellable one-person Flux recovery confirmation without native dialogs', function () {
    $this->actingAs(adminUser());
    currentCompany()->update(['workos_organization_id' => 'org_test']);
    $person = User::factory()->create(['name' => 'Revoked Detail Recipient']);
    $person->forceFill(['invitation_state' => WorkosInvitationState::Revoked])->save();
    Queue::fake();
    $page = visit(route('people.show', ['user' => $person], false))
        ->click('button[wire\\:click="confirmInvitation"]')->assertSee('This action covers one person: Revoked Detail Recipient ('.$person->email.').')
        ->assertSee('Selected revoked invitations will receive a new invitation after verification.')
        ->keys('dialog[open] button[wire\\:click="sendInvitation"]', 'Escape')->assertMissing('dialog[open]');
    expect(WorkosInvitationAttempt::count())->toBe(0);
    $page->click('button[wire\\:click="confirmInvitation"]')->click('dialog[open] button[wire\\:click="sendInvitation"]')->assertMissing('dialog[open]')->assertSee('Invitation queued through WorkOS.')->assertNoJavascriptErrors();
    expect(WorkosInvitationAttempt::pluck('person_id')->all())->toBe([$person->id]);
});

it('TA-06 synchronizes populated and no-invitation people through pending polling to honest outcomes', function (int $width, bool $partial) {
    Queue::fake();
    Http::preventStrayRequests();
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_browser_sync']);
    $actor = userWithPermissions([Permission::PeopleView, Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => 'user_browser_sync_actor', 'account_id' => Account::factory()->create()->id])->save();
    $this->actingAs($actor);
    Fixture::invitationLink($actor);
    $person = User::factory()->create(['name' => 'Browser synchronization target', 'workos_user_id' => 'user_browser_sync_target', 'workos_invitation_id' => 'invitation_browser_sync', 'invitation_generation' => 1, 'invitation_state' => WorkosInvitationState::Pending]);
    $person->forceFill(['invitation_verified_at' => now()->subDay(), 'invitation_accepted_history_at' => now()->subDays(3)])->save();
    Fixture::invitationLink($person);
    $empty = User::factory()->create(['name' => 'Empty invitation peer', 'workos_user_id' => 'user_browser_empty']);
    Fixture::invitationLink($empty);
    $before = $person->fresh()->only(['status', 'workos_active_membership', 'first_access_at', 'invitation_accepted_history_at']);
    $verified = $person->fresh()->invitation_verified_at;
    config(['services.account.invitation_ownership_enabled' => true, 'services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
    $complete = false;
    $inputs = [];
    Http::fake(['account.example.test/*' => function ($request) use (&$complete, &$inputs, $partial, $person) {
        $id = basename(parse_url($request->url(), PHP_URL_PATH));
        if ($request->method() === 'PUT') {
            $inputs[$id] = $request->data();
        }$input = $inputs[$id];
        if (! $complete) {
            return Http::response(array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'unknown', 'confirmed_state' => [], 'safe_error' => 'invitation_confirmation_unavailable']);
        }if ($partial && $input['payload']['local_person_id'] === $person->id) {
            return Http::response(array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'needs_attention', 'confirmed_state' => [], 'safe_error' => 'invitation_confirmation_unavailable']);
        }$target = User::findOrFail($input['payload']['local_person_id']);

        return Http::response(Fixture::observationReceipt($input, $target, $target->workos_invitation_id ? 'expired' : 'none'));
    }]);
    $button = 'button[wire\\:click="synchronize"]';
    $search = 'input[placeholder="Name, email or employee ID"]';
    $page = visit(route('people.index', [], false))->resize($width, 900)->click($button)->assertSee('Synchronization queued. No invitations will be sent.')->assertSee('Waiting to start')->assertDisabled($button);
    $run = WorkosSyncRun::sole();
    $job = new ReconcileWorkosInvitations($company->id, $actor->id, $run->id);
    $job->protocolVersion = 2;
    $job->handle(app(App\Actions\People\ReconcileWorkosInvitations::class));
    $page->assertSee('Synchronization: Running')->assertDisabled($button)->fill($search, 'Browser synchronization target')->assertSee($person->name);
    expect($run->fresh()->status)->toBe(WorkosOperationStatus::Running)->and($person->fresh()->invitation_verified_at->equalTo($verified))->toBeTrue();
    $complete = true;
    $page->assertSee($partial ? 'Synchronization: Partial failure' : 'Synchronization: Completed')->assertSee('Processed 3 of 3')->assertEnabled($button)->assertValue($search, 'Browser synchronization target')->assertAttribute('section[aria-label="WorkOS invitations"] [role="status"]', 'aria-live', 'polite')->assertScript("document.activeElement.getAttribute('placeholder')", 'Name, email or employee ID')->assertNoJavascriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    expect($person->fresh()->only(array_keys($before)))->toEqual($before)->and($empty->fresh()->invitation_state)->toBe(WorkosInvitationState::NotInvited);
    if ($partial) {
        $page->assertSee('Automatic retries stopped. Review the Account communication.')->assertSee('Latest check failed');
        expect($person->fresh()->invitation_verified_at->equalTo($verified))->toBeTrue();
    } else {
        $page->assertSee('Expired');
        expect($person->fresh()->invitation_state)->toBe(WorkosInvitationState::Expired);
    }
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
})->with([390, 1440])->with([false, true]);

it('TA-06 hides view-only controls and stops queued synchronization after permission revocation', function (int $width) {
    Queue::fake();
    Http::preventStrayRequests();
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_browser_revoked']);
    $actor = userWithPermissions([Permission::PeopleView, Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => 'user_revoked_browser', 'account_id' => Account::factory()->create()->id])->save();
    $this->actingAs($actor);
    $page = visit(route('people.index', [], false))->resize($width, 900)->click('button[wire\\:click="synchronize"]')->assertSee('Waiting to start');
    $run = WorkosSyncRun::sole();
    $permission = App\Models\Permission::where('key', Permission::PeopleSyncWorkos->value)->sole();
    foreach ($actor->roles()->get() as $role) {
        $role->permissions()->detach($permission->id);
    }
    $job = new ReconcileWorkosInvitations($company->id, $actor->id, $run->id);
    $job->protocolVersion = 2;
    $job->handle(app(App\Actions\People\ReconcileWorkosInvitations::class));
    $page->assertSee('Synchronization: Failed')->assertSee('Permission revoked')->assertMissing('button[wire\\:click="synchronize"]')->assertSee('WorkOS invitations')->assertNoJavascriptErrors();
    expect(AccountRequestOperation::count())->toBe(0)->and($run->fresh()->reason)->toBe('permission_revoked');
    Http::assertNothingSent();
})->with([390, 1440]);
