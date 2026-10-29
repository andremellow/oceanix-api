<?php

use App\Actions\People\ReconcileWorkosInvitations;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Enums\WorkosInvitationState;
use App\Enums\WorkosOperationStatus;
use App\Models\AccountRequestOperation;
use App\Models\User;
use App\Models\WorkosSyncRun;
use App\Tenancy\TenantContext;
use Database\Factories\AccountFactory;
use Database\Factories\CompanyFactory;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AccountIntegrationFixture as Fixture;

/** Existing synchronization regressions start with a known legacy verification baseline. */
function knownSyncInvitationLink(User $person): void
{
    if ($person->invitation_verified_at === null) {
        $person->forceFill(['invitation_verified_at' => now()->subDays(3)])->save();
    }
    Fixture::invitationLink($person);
}

beforeEach(function () {
    config(['services.account.invitation_ownership_enabled' => true, 'services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
    Http::preventStrayRequests();
    currentCompany()->update(['workos_organization_id' => 'org_synthetic']);
});

it('SC-07 SC-13 receives every lifecycle through Account while preserving operational access', function (string $state, bool $active) {
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => 'user_sync_actor', 'account_id' => AccountFactory::new()->create()->id])->save();
    knownSyncInvitationLink($actor);
    $person = employeeUser();
    $person->forceFill(['workos_user_id' => 'user_exact', 'workos_invitation_id' => $state === 'none' ? null : 'invitation_exact', 'invitation_generation' => 3, 'workos_active_membership' => $active])->save();
    knownSyncInvitationLink($person);
    $before = $person->fresh()->only(['status', 'workos_active_membership', 'account_id', 'first_access_at']);
    $roles = DB::table('role_user')->get()->toJson();
    Http::fake(['account.example.test/*' => fn ($request) => Http::response(Fixture::observationReceipt($request->data(), $person, $state))]);
    expect(app(ReconcileWorkosInvitations::class)->refresh($person, $actor->id))->toBeTrue();
    expect($person->fresh()->invitation_state)->toBe($state === 'none' ? WorkosInvitationState::NotInvited : WorkosInvitationState::from($state))->and($person->fresh()->only(array_keys($before)))->toBe($before)->and(DB::table('role_user')->get()->toJson())->toBe($roles);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
})->with(['pending', 'accepted', 'expired', 'revoked', 'none'])->with([false, true]);

it('SC-16 preserves last verification on transport and malformed receipt failures', function (string $failure) {
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => 'user_sync_actor', 'account_id' => AccountFactory::new()->create()->id])->save();
    knownSyncInvitationLink($actor);
    $person = employeeUser();
    $person->forceFill(['workos_invitation_id' => 'invitation_retained', 'invitation_generation' => 2, 'invitation_state' => WorkosInvitationState::Accepted, 'invitation_verified_at' => now()->subDay(), 'invitation_accepted_history_at' => now()->subDays(2)])->save();
    knownSyncInvitationLink($person);
    $before = $person->fresh()->getAttributes();
    Http::fake(['account.example.test/*' => fn () => $failure === 'network' ? Http::failedConnection() : Http::response(['outcome' => 'confirmed'])]);
    expect(fn () => app(ReconcileWorkosInvitations::class)->refresh($person, $actor->id))->toThrow(Exception::class);
    expect($person->fresh()->getAttributes())->toBe($before);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
})->with(['network', 'malformed']);

it('SC-15 rejects revoked actors before any Account or provider request with an authorized positive control', function () {
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => 'user_sync_actor', 'account_id' => AccountFactory::new()->create()->id])->save();
    knownSyncInvitationLink($actor);
    $person = employeeUser();
    knownSyncInvitationLink($person);
    Http::fake(['account.example.test/*' => fn ($request) => Http::response(Fixture::observationReceipt($request->data(), $person, 'none'))]);
    app(ReconcileWorkosInvitations::class)->refresh($person, $actor->id);
    $actor->roles()->detach();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    expect(fn () => app(ReconcileWorkosInvitations::class)->refresh($person, $actor->id))->toThrow(Exception::class);
    Http::assertNothingSent();
});

it('SC-12 keeps a queued sync running until its original Account receipt completes', function () {
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => 'user_sync_actor', 'account_id' => AccountFactory::new()->create()->id])->save();
    knownSyncInvitationLink($actor);
    $person = employeeUser();
    knownSyncInvitationLink($person);
    $run = WorkosSyncRun::create(['actor_id' => $actor->id, 'status' => WorkosOperationStatus::Queued]);
    Http::fake(['account.example.test/*' => function ($request) {
        return Http::response(array_intersect_key($request->data(), array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'pending', 'confirmed_state' => []]);
    }]);
    // Limit this representative run to its two known local people; each keeps independent work.
    app(ReconcileWorkosInvitations::class)->handle($run);
    expect($run->fresh()->status)->toBe(WorkosOperationStatus::Running)->and($run->fresh()->failed)->toBe(0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});

it('SC-18 reports exhausted Account targets as terminal failures without replacing last verified evidence', function () {
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => 'user_terminal_actor', 'account_id' => AccountFactory::new()->create()->id])->save();
    $person = employeeUser();
    knownSyncInvitationLink($actor);
    knownSyncInvitationLink($person);
    $person->forceFill(['invitation_verified_at' => now()->subDay()])->save();
    $verified = $person->fresh()->invitation_verified_at;
    $run = WorkosSyncRun::create(['actor_id' => $actor->id, 'status' => WorkosOperationStatus::Queued]);
    $terminal = false;
    $inputs = [];
    Http::fake(['account.example.test/*' => function ($request) use (&$terminal, &$inputs) {
        $id = basename(parse_url($request->url(), PHP_URL_PATH));
        if ($request->method() === 'PUT') {
            $inputs[$id] = $request->data();
        }
        $input = $inputs[$id];

        return Http::response(array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => $terminal ? 'needs_attention' : 'pending', 'confirmed_state' => [], 'safe_error' => $terminal ? 'invitation_confirmation_unavailable' : null]);
    }]);
    app(ReconcileWorkosInvitations::class)->handle($run);
    expect($run->fresh()->status)->toBe(WorkosOperationStatus::Running);
    $terminal = true;
    app(ReconcileWorkosInvitations::class)->refreshPending($actor);
    expect($run->fresh()->status)->toBe(WorkosOperationStatus::Failed)->and($run->fresh()->failed)->toBe(2)->and(AccountRequestOperation::where('state', 'needs_attention')->count())->toBe(2)->and($person->fresh()->invitation_verified_at->equalTo($verified))->toBeTrue();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});

it('SC-19 preserves a newer invitation and independent access written while an Account result is in flight', function () {
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => 'user_interleaved_actor', 'account_id' => AccountFactory::new()->create()->id])->save();
    $person = employeeUser();
    $person->forceFill(['invitation_generation' => 1, 'workos_invitation_id' => 'invitation_old'])->save();
    knownSyncInvitationLink($person);
    Http::fake(['account.example.test/*' => function ($request) use ($person) {
        $receipt = Fixture::observationReceipt($request->data(), $person, 'expired');
        $person->forceFill(['workos_invitation_id' => 'invitation_newer', 'invitation_generation' => 100, 'status' => UserStatus::Suspended])->save();

        return Http::response($receipt);
    }]);
    app(ReconcileWorkosInvitations::class)->refresh($person, $actor->id);
    expect($person->fresh()->workos_invitation_id)->toBe('invitation_newer')->and($person->fresh()->invitation_generation)->toBe(100)->and($person->fresh()->status)->toBe(UserStatus::Suspended);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});

it('SC-13 SC-21 rejects malformed or foreign Account observations before any local projection', function (string $failure) {
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => 'user_validation_actor', 'account_id' => AccountFactory::new()->create()->id])->save();
    $person = employeeUser();
    $person->forceFill(['workos_invitation_id' => 'invitation_exact', 'invitation_generation' => 1])->save();
    knownSyncInvitationLink($person);
    $before = $person->fresh()->getAttributes();
    Http::fake(['account.example.test/*' => function ($request) use ($person, $failure) {
        $receipt = Fixture::observationReceipt($request->data(), $person, 'pending');
        match ($failure) {
            'state' => $receipt['confirmed_state']['observation']['invitation']['state'] = 'unknown',
            'date' => $receipt['confirmed_state']['observation']['verified_at'] = 'not-a-date',
            'typed-sequence' => $receipt['confirmed_state']['observation']['observation_sequence'] = '1',
            'identity' => $receipt['confirmed_state']['observation']['workos_user_id'] = 'user_foreign',
            'organization' => $receipt['workos_organization_id'] = 'org_foreign',
            'invitation' => $receipt['confirmed_state']['observation']['invitation']['id'] = 'invitation_foreign',
        };

        return Http::response($receipt);
    }]);
    expect(fn () => app(ReconcileWorkosInvitations::class)->refresh($person, $actor->id))->toThrow(Exception::class);
    expect($person->fresh()->getAttributes())->toBe($before);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
})->with(['state', 'date', 'typed-sequence', 'identity', 'organization', 'invitation']);

it('SC-15 restores tenant context after a real new-format synchronization job completes', function () {
    $company = currentCompany();
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => 'user_context_actor', 'account_id' => AccountFactory::new()->create()->id])->save();
    $person = employeeUser();
    knownSyncInvitationLink($actor);
    knownSyncInvitationLink($person);
    $run = WorkosSyncRun::create(['actor_id' => $actor->id, 'status' => WorkosOperationStatus::Queued]);
    Http::fake(['account.example.test/*' => function ($request) {
        $person = User::whereKey($request['payload']['local_person_id'])->firstOrFail();

        return Http::response(Fixture::observationReceipt($request->data(), $person, 'none'));
    }]);
    $other = CompanyFactory::new()->create();
    app(TenantContext::class)->set($other);
    $job = new App\Jobs\ReconcileWorkosInvitations($company->id, $actor->id, $run->id);
    $job->protocolVersion = 2;
    $job->handle(app(ReconcileWorkosInvitations::class));
    expect(app(TenantContext::class)->id())->toBe($other->id)->and($run->fresh()->status)->toBe(WorkosOperationStatus::Completed);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});

it('CR-04 retains transient unknown polling and later completes the original Account operations', function () {
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => 'user_transient_actor', 'account_id' => AccountFactory::new()->create()->id])->save();
    knownSyncInvitationLink($actor);
    $person = employeeUser();
    knownSyncInvitationLink($person);
    $person->forceFill(['invitation_verified_at' => now()->subDay()])->save();
    $verified = $person->fresh()->invitation_verified_at;
    $run = WorkosSyncRun::create(['actor_id' => $actor->id, 'status' => WorkosOperationStatus::Queued]);
    $success = false;
    $inputs = [];
    Http::fake(['account.example.test/*' => function ($request) use (&$inputs, &$success) {
        $id = basename(parse_url($request->url(), PHP_URL_PATH));
        if ($request->method() === 'PUT') {
            $inputs[$id] = $request->data();
        }
        $input = $inputs[$id];
        if ($success) {
            return Http::response(Fixture::observationReceipt($input, User::findOrFail($input['payload']['local_person_id']), 'none'));
        }

        return Http::response(array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'unknown', 'confirmed_state' => [], 'safe_error' => 'invitation_confirmation_unavailable']);
    }]);
    app(ReconcileWorkosInvitations::class)->handle($run);
    $ids = AccountRequestOperation::pluck('payload_hash', 'id')->all();
    app(ReconcileWorkosInvitations::class)->refreshPending($actor);
    expect($run->fresh()->status)->toBe(WorkosOperationStatus::Running)->and(AccountRequestOperation::where('state', 'needs_attention')->count())->toBe(0)->and($person->fresh()->invitation_verified_at->equalTo($verified))->toBeTrue();
    $success = true;
    app(ReconcileWorkosInvitations::class)->refreshPending($actor);
    expect($run->fresh()->status)->toBe(WorkosOperationStatus::Completed)->and($run->fresh()->failed)->toBe(0)->and(AccountRequestOperation::pluck('payload_hash', 'id')->all())->toBe($ids)->and(AccountRequestOperation::where('state', 'confirmed')->count())->toBe(2);
});
