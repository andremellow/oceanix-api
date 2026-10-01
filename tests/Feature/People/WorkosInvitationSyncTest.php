<?php

use App\Actions\People\QueueWorkosSynchronization;
use App\Actions\People\ReconcileWorkosInvitations;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Enums\WorkosInvitationState;
use App\Enums\WorkosOperationStatus;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkosSyncRun;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    currentCompany()->update(['workos_organization_id' => 'org_current']);
    config(['services.workos.api_key' => 'test-key']);
});

it('maps supported invitation states without sending mail', function ($state) {
    $person = User::factory()->create(['workos_user_id' => null, 'workos_invitation_id' => 'inv_current', 'status' => UserStatus::Invited]);
    $data = invitationFixture($person, $state);
    Http::fake(['*/invitations/inv_current' => Http::response($data), '*/invitations?*' => Http::response(['data' => [$data], 'list_metadata' => ['after' => null]])]);
    expect(app(ReconcileWorkosInvitations::class)->refresh($person))->toBeTrue();
    expect($person->fresh()->invitation_state)->toBe(WorkosInvitationState::from($state))->and($person->fresh()->status)->toBe(UserStatus::Invited);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
})->with(['pending', 'accepted', 'expired', 'revoked']);

it('paginates and selects current invitations retaining accepted history', function () {
    $person = User::factory()->create(['workos_user_id' => null]);
    Http::fake(function ($request) use ($person) {
        return Http::response(($request->data()['after'] ?? null) === 'next' ? ['data' => [invitationFixture($person, 'expired', 'inv_new', ['created_at' => '2026-09-10T10:00:00Z'])], 'list_metadata' => ['after' => null]] : ['data' => [invitationFixture($person, 'accepted', 'inv_old')], 'list_metadata' => ['after' => 'next']]);
    });
    app(ReconcileWorkosInvitations::class)->refresh($person);
    expect($person->fresh()->workos_invitation_id)->toBe('inv_new')->and($person->fresh()->invitation_accepted_history_at)->not->toBeNull();
    Http::assertSentCount(2);
});

it('matches invitation email and company exactly', function () {
    $person = User::factory()->create(['workos_user_id' => null]);
    Http::fake(['*/invitations?*' => Http::response(['data' => [invitationFixture($person, 'accepted', 'inv_foreign', ['organization_id' => 'org_other']), invitationFixture($person, 'pending', 'inv_match', ['email' => strtoupper($person->email)]), invitationFixture($person, 'accepted', 'inv_other', ['email' => 'other@example.com'])]])]);
    app(ReconcileWorkosInvitations::class)->refresh($person);
    expect($person->fresh()->workos_invitation_id)->toBe('inv_match');
});

it('rejects unavailable and contradictory stored records', function ($payload) {
    $person = User::factory()->create(['workos_user_id' => null, 'workos_invitation_id' => 'inv_current']);
    Http::fake(['*/invitations/inv_current' => $payload === '404' ? Http::response([], 404) : Http::response(invitationFixture($person, 'pending', 'inv_current', ['email' => 'different@example.com'])), '*/invitations?*' => Http::response(['data' => []])]);
    app(ReconcileWorkosInvitations::class)->refresh($person);
    expect($person->fresh()->invitation_state)->toBe(WorkosInvitationState::Unverified)->and($person->fresh()->first_access_at)->toBeNull();
})->with(['404', 'mismatch']);

it('retains evidence on partial provider failure with bounded retries', function () {
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => null, 'account_id' => Account::factory()->create(['workos_user_id' => null])->id])->save();
    $good = User::factory()->create(['workos_user_id' => null]);
    $bad = User::factory()->create(['workos_user_id' => null]);
    $bad->forceFill(['invitation_state' => WorkosInvitationState::Pending, 'invitation_verified_at' => now()->subDay()])->save();
    $before = $bad->invitation_verified_at;
    $badRequests = 0;
    Http::fake(function ($request) use ($bad, $good, &$badRequests) {
        if ($request['email'] === $bad->email) {
            $badRequests++;

            return Http::response([], 429);
        }

        return Http::response(['data' => $request['email'] === $good->email ? [invitationFixture($good)] : []]);
    });
    $run = WorkosSyncRun::create(['actor_id' => $actor->id]);
    app(ReconcileWorkosInvitations::class)->handle($run);
    expect($run->fresh()->status)->toBe(WorkosOperationStatus::PartialFailure)->and($run->fresh()->failed)->toBe(1)->and($badRequests)->toBe(3)
        ->and($bad->fresh()->invitation_state)->toBe(WorkosInvitationState::Pending)->and($bad->fresh()->invitation_verified_at->equalTo($before))->toBeTrue()->and($bad->fresh()->invitation_check_error)->toBe('provider_verification_failed');
});

it('keeps external signin separate from tenant access', function () {
    $person = User::factory()->create(['status' => UserStatus::Invited]);
    Http::fake(['*/invitations?*' => Http::response(['data' => []]), '*/users/*' => Http::response(['id' => $person->workos_user_id, 'email' => $person->email, 'last_sign_in_at' => '2026-09-20T10:00:00Z']), '*/organization_memberships?*' => Http::response(['data' => []])]);
    app(ReconcileWorkosInvitations::class)->refresh($person);
    expect($person->fresh()->workos_last_sign_in_at)->not->toBeNull()->and($person->fresh()->first_access_at)->toBeNull()->and($person->fresh()->status)->toBe(UserStatus::Invited);
});

it('enforces sync profile authorization and durable overlap prevention', function () {
    Queue::fake();
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    expect($actor->hasPermission(Permission::PeopleView))->toBeTrue();
    $this->actingAs($actor);
    $one = app(QueueWorkosSynchronization::class)->handle();
    expect(app(QueueWorkosSynchronization::class)->handle()->id)->toBe($one->id)->and(WorkosSyncRun::count())->toBe(1);
    $actor->roles()->update(['archived_at' => now()]);
    expect(fn () => app(QueueWorkosSynchronization::class)->handle())->toThrow(AuthorizationException::class);
    $this->actingAs(adminUser());
    expect(app(QueueWorkosSynchronization::class)->handle()->id)->toBe($one->id);
});

it('preserves newer access and invitation writes during a provider read', function () {
    $person = User::factory()->create(['workos_user_id' => null, 'workos_invitation_id' => 'inv_current']);
    Http::fake(function ($request) use ($person) {
        if (str_contains($request->url(), '/invitations?')) {
            $person->forceFill(['workos_invitation_id' => 'inv_newer', 'invitation_generation' => 1, 'status' => UserStatus::Active, 'first_access_at' => now()])->save();

            return Http::response(['data' => []]);
        }

        return Http::response(invitationFixture($person));
    });
    $stale = $person->fresh();
    expect(app(ReconcileWorkosInvitations::class)->refresh($stale))->toBeFalse();
    expect($person->fresh()->workos_invitation_id)->toBe('inv_newer')->and($person->fresh()->first_access_at)->not->toBeNull();
});

it('restores tenant context after sync job success and denied actor', function () {
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => null, 'account_id' => Account::factory()->create(['workos_user_id' => null])->id])->save();
    $company = currentCompany();
    $run = WorkosSyncRun::create(['actor_id' => $actor->id]);
    Http::fake(['*' => Http::response(['data' => []])]);
    $other = Company::factory()->create();
    app(TenantContext::class)->set($other);
    (new App\Jobs\ReconcileWorkosInvitations($company->id, $actor->id, $run->id))->handle(app(ReconcileWorkosInvitations::class));
    expect(app(TenantContext::class)->id())->toBe($other->id);
});

it('rolls back the snapshot when its atomic audit write fails', function () {
    $person = User::factory()->create(['workos_user_id' => null]);
    Http::fake(['*' => Http::response(['data' => [invitationFixture($person)]])]);
    AuditLog::creating(function () {
        throw new RuntimeException('injected audit persistence failure');
    });
    try {
        expect(fn () => app(ReconcileWorkosInvitations::class)->refresh($person))->toThrow(RuntimeException::class);
        expect($person->fresh()->workos_invitation_id)->toBeNull()->and($person->fresh()->invitation_generation)->toBe(0)->and($person->fresh()->invitation_verified_at)->toBeNull();
    } finally {
        AuditLog::flushEventListeners();
    }
});

it('rejects malformed snapshots and repeated cursors instead of proving absence', function ($malformed) {
    $person = User::factory()->create(['workos_user_id' => null]);
    Http::fake(['*' => Http::response($malformed ? ['data' => [invitationFixture($person, 'pending', 'inv_current', ['created_at' => 'not-a-date'])]] : ['data' => [], 'list_metadata' => ['after' => 'loop']])]);
    expect(fn () => app(ReconcileWorkosInvitations::class)->refresh($person))->toThrow(RuntimeException::class);
    expect($person->fresh()->invitation_verified_at)->toBeNull();
})->with([true, false]);
