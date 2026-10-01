<?php

use App\Actions\People\QueueWorkosInvitations;
use App\Actions\People\SendWorkosInvitation;
use App\Enums\Permission;
use App\Enums\WorkosInvitationState;
use App\Enums\WorkosOperationStatus;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkosInvitationAttempt;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    currentCompany()->update(['workos_organization_id' => 'org_current']);
    config(['services.workos.api_key' => 'test-key']);
    $this->actingAs(userWithPermissions([Permission::PeopleInvite]));
    auth()->user()->forceFill(['account_id' => Account::factory()->create()->id])->save();
    Queue::fake();
});

it('resends pending and reissues expired invitations', function ($state) {
    $person = User::factory()->create(['workos_user_id' => null, 'workos_invitation_id' => 'inv_current']);
    Http::fake(function ($request) use ($person, $state) {
        if ($request->method() === 'POST') {
            return Http::response(invitationFixture($person, 'pending', $state === 'pending' ? 'inv_current' : 'inv_new'));
        }

        return Http::response(str_contains($request->url(), '/invitations?') ? ['data' => [invitationFixture($person, $state)]] : invitationFixture($person, $state));
    });
    expect(app(QueueWorkosInvitations::class)->handle([$person->id]))->toBe(1);
    $attempt = WorkosInvitationAttempt::firstOrFail();
    app(SendWorkosInvitation::class)->execute($attempt);
    expect($attempt->fresh()->status)->toBe(WorkosOperationStatus::Succeeded)->and($person->fresh()->invitation_sent_at)->not->toBeNull();
    Http::assertSent(fn ($r) => $r->method() === 'POST' && ($state === 'pending' ? str_ends_with($r->url(), '/inv_current/resend') : str_ends_with($r->url(), '/invitations')));
})->with(['pending', 'expired']);

it('skips accepted members and blocked people with an eligible positive control', function () {
    $accepted = User::factory()->create(['workos_user_id' => null]);
    $member = User::factory()->create(['workos_user_id' => 'user_member']);
    $blocked = User::factory()->suspended()->create(['workos_user_id' => null]);
    $good = User::factory()->create(['workos_user_id' => null]);
    Http::fake(function ($r) use ($accepted, $member, $good) {
        if (str_contains($r->url(), '/users/')) {
            return Http::response(['id' => 'user_member', 'email' => $member->email]);
        }
        if (str_contains($r->url(), '/organization_memberships')) {
            return Http::response(['data' => [['user_id' => 'user_member', 'organization_id' => 'org_current', 'status' => 'active']]]);
        }
        if ($r->method() === 'POST') {
            return Http::response(invitationFixture($good));
        }

        return Http::response(['data' => $r['email'] === $accepted->email ? [invitationFixture($accepted, 'accepted')] : ($r['email'] === $member->email ? [invitationFixture($member, 'expired')] : [])]);
    });
    app(QueueWorkosInvitations::class)->handle([$accepted->id, $member->id, $blocked->id, $good->id]);
    WorkosInvitationAttempt::all()->each(fn ($attempt) => app(SendWorkosInvitation::class)->execute($attempt));
    expect(WorkosInvitationAttempt::where('status', 'succeeded')->count())->toBe(1)->and(WorkosInvitationAttempt::where('status', 'skipped')->count())->toBe(3);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['email'] === $good->email);
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST'))->toHaveCount(1);
});

it('distinguishes default and explicit revoked recovery', function () {
    $revoked = User::factory()->create(['workos_user_id' => null]);
    $revoked->forceFill(['invitation_state' => WorkosInvitationState::Revoked])->save();
    $fresh = User::factory()->create(['workos_user_id' => null]);
    app(QueueWorkosInvitations::class)->handle([], true);
    expect(WorkosInvitationAttempt::where('person_id', $revoked->id)->exists())->toBeFalse()->and(WorkosInvitationAttempt::where('person_id', $fresh->id)->exists())->toBeTrue();
    app(QueueWorkosInvitations::class)->handle([$revoked->id]);
    $attempt = WorkosInvitationAttempt::where('person_id', $revoked->id)->firstOrFail();
    Http::fake(fn ($r) => Http::response($r->method() === 'POST' ? invitationFixture($revoked) : ['data' => [invitationFixture($revoked, 'revoked')]]));
    app(SendWorkosInvitation::class)->execute($attempt);
    expect($attempt->fresh()->status)->toBe(WorkosOperationStatus::Succeeded);
});

it('guards repeated sends retries and revoked actors', function () {
    $person = User::factory()->create(['workos_user_id' => null]);
    expect(app(QueueWorkosInvitations::class)->handle([$person->id]))->toBe(1)->and(app(QueueWorkosInvitations::class)->handle([$person->id]))->toBe(0);
    $attempt = WorkosInvitationAttempt::firstOrFail();
    auth()->user()->roles()->update(['archived_at' => now()]);
    Http::fake();
    app(SendWorkosInvitation::class)->execute($attempt);
    expect($attempt->fresh()->reason)->toBe('permission_revoked');
    Http::assertNothingSent();
});

it('records uncertain remote delivery and never blindly replays a POST', function () {
    $person = User::factory()->create(['workos_user_id' => null]);
    app(QueueWorkosInvitations::class)->handle([$person->id]);
    $attempt = WorkosInvitationAttempt::firstOrFail();
    Http::fake(fn ($r) => $r->method() === 'POST' ? Http::response([], 503) : Http::response(['data' => []]));
    app(SendWorkosInvitation::class)->execute($attempt);
    app(SendWorkosInvitation::class)->execute($attempt->fresh());
    expect($attempt->fresh()->status)->toBe(WorkosOperationStatus::DeliveryUnconfirmed);
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST'))->toHaveCount(1);
});

it('preserves a newer invitation after a successful but stale POST and restores job context', function () {
    $person = User::factory()->create(['workos_user_id' => null]);
    app(QueueWorkosInvitations::class)->handle([$person->id]);
    $attempt = WorkosInvitationAttempt::firstOrFail();
    Http::fake(function ($r) use ($person) {
        if ($r->method() === 'POST') {
            $person->forceFill(['workos_invitation_id' => 'inv_newer', 'invitation_generation' => 100])->save();

            return Http::response(invitationFixture($person, 'pending', 'inv_post'));
        }

        return Http::response(['data' => []]);
    });
    $company = currentCompany();
    $other = Company::factory()->create();
    app(TenantContext::class)->set($other);
    (new App\Jobs\SendWorkosInvitation($company->id, $person->id, $attempt->actor_id, $attempt->id))->handle(app(SendWorkosInvitation::class));
    expect(app(TenantContext::class)->id())->toBe($other->id);
    app(TenantContext::class)->set($company);
    expect($attempt->fresh()->status)->toBe(WorkosOperationStatus::DeliveryUnconfirmed)->and($person->fresh()->workos_invitation_id)->toBe('inv_newer');
});

it('reauthorizes sending recovery before any provider access', function () {
    $person = User::factory()->create(['workos_user_id' => null]);
    app(QueueWorkosInvitations::class)->handle([$person->id]);
    $attempt = WorkosInvitationAttempt::firstOrFail();
    $attempt->update(['status' => WorkosOperationStatus::Sending, 'send_started_at' => now()]);
    auth()->user()->roles()->update(['archived_at' => now()]);
    Http::fake(['*' => Http::response(['data' => []])]);
    app(SendWorkosInvitation::class)->execute($attempt);
    Http::assertNothingSent();
    expect($attempt->fresh()->status)->toBe(WorkosOperationStatus::DeliveryUnconfirmed)->and($attempt->fresh()->reason)->toBe('permission_revoked')->and($person->fresh()->invitation_verified_at)->toBeNull();
});

it('fails malformed recovery before POST and preserves prior verification', function () {
    $person = User::factory()->create(['workos_user_id' => null]);
    $person->forceFill(['invitation_state' => WorkosInvitationState::Pending, 'invitation_verified_at' => now()->subDay()])->save();
    $verified = $person->invitation_verified_at;
    app(QueueWorkosInvitations::class)->handle([$person->id]);
    Http::fake(['*' => Http::response(['data' => [123]])]);
    $attempt = WorkosInvitationAttempt::firstOrFail();
    app(SendWorkosInvitation::class)->execute($attempt);
    expect($attempt->fresh()->status)->toBe(WorkosOperationStatus::Failed)->and($attempt->fresh()->reason)->toBe('provider_verification_failed')->and($person->fresh()->invitation_verified_at->equalTo($verified))->toBeTrue()->and($person->fresh()->invitation_state)->toBe(WorkosInvitationState::Pending);
    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});

it('recovers an interrupted sending intent using reads only and honest audit', function () {
    $person = User::factory()->create(['workos_user_id' => null]);
    app(QueueWorkosInvitations::class)->handle([$person->id]);
    $attempt = WorkosInvitationAttempt::firstOrFail();
    $attempt->update(['status' => WorkosOperationStatus::Sending, 'send_started_at' => now()->subMinute()]);
    Http::fake(['*' => Http::response(['data' => [invitationFixture($person)]])]);
    app(SendWorkosInvitation::class)->execute($attempt);
    expect($attempt->fresh()->status)->toBe(WorkosOperationStatus::DeliveryUnconfirmed)->and($person->fresh()->invitation_state)->toBe(WorkosInvitationState::Pending);
    expect(AuditLog::where('action', 'person.workos_invitation_outcome')->latest('id')->first()->metadata)->toBe(['status' => 'delivery_unconfirmed', 'reason' => 'delivery_unconfirmed']);
    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});

it('rolls back successful send persistence and never replays after local failure', function () {
    $person = User::factory()->create(['workos_user_id' => null]);
    app(QueueWorkosInvitations::class)->handle([$person->id]);
    $attempt = WorkosInvitationAttempt::firstOrFail();
    $postCount = 0;
    Http::fake(function ($r) use ($person, &$postCount) {
        if ($r->method() === 'POST') {
            $postCount++;

            return Http::response(invitationFixture($person, 'pending', 'inv_delivered'));
        }

        return Http::response(['data' => []]);
    });
    $injected = false;
    AuditLog::creating(function ($log) use (&$injected) {
        if ($log->action === 'person.workos_invitation_sent' && ! $injected) {
            $injected = true;
            throw new RuntimeException('one send audit failure');
        }
    });
    try {
        app(SendWorkosInvitation::class)->execute($attempt);
        app(SendWorkosInvitation::class)->execute($attempt->fresh());
        expect($postCount)->toBe(1)->and($attempt->fresh()->status)->toBe(WorkosOperationStatus::DeliveryUnconfirmed)->and($attempt->fresh()->resulting_invitation_id)->toBeNull()->and($person->fresh()->workos_invitation_id)->toBeNull()->and($person->fresh()->invitation_sent_at)->toBeNull()->and($person->fresh()->invitation_generation)->toBe(1)->and(AuditLog::where('action', 'person.workos_invitation_sent')->exists())->toBeFalse();
    } finally {
        AuditLog::flushEventListeners();
    }
});

it('persists truthful send job failure before and after reservation', function ($reserved) {
    $person = User::factory()->create(['workos_user_id' => null]);
    app(QueueWorkosInvitations::class)->handle([$person->id]);
    $attempt = WorkosInvitationAttempt::firstOrFail();
    if ($reserved) {
        $attempt->update(['status' => WorkosOperationStatus::Sending, 'send_started_at' => now()]);
    }
    (new App\Jobs\SendWorkosInvitation(currentCompany()->id, $person->id, $attempt->actor_id, $attempt->id))->failed(new RuntimeException('worker interrupted'));
    expect($attempt->fresh()->status)->toBe($reserved ? WorkosOperationStatus::DeliveryUnconfirmed : WorkosOperationStatus::Failed)->and($attempt->fresh()->reason)->toBe($reserved ? 'delivery_unconfirmed' : 'send_failed')->and($attempt->fresh()->finished_at)->not->toBeNull()->and($person->fresh()->invitation_sent_at)->toBeNull();
})->with([false, true]);

it('executes exact default partitions with fresh exclusions and server send evidence', function () {
    auth()->user()->forceFill(['workos_active_membership' => true])->save();
    $this->travelTo(now()->startOfSecond());
    $sent = now();
    $people = collect(['pending', 'expired', 'unverified', 'not_invited', 'accepted', 'revoked', 'member', 'suspended', 'terminated', 'fresh_accepted', 'fresh_member', 'null'])->mapWithKeys(function ($state) {
        $person = User::factory()->create(['name' => 'Partition '.$state, 'workos_user_id' => in_array($state, ['member', 'fresh_member']) ? 'user_'.$state : null, 'status' => in_array($state, ['suspended', 'terminated']) ? $state : 'invited']);
        $person->forceFill(['invitation_state' => in_array($state, ['member', 'suspended', 'terminated', 'fresh_accepted', 'fresh_member']) ? WorkosInvitationState::Pending : ($state === 'null' ? null : WorkosInvitationState::from($state)), 'workos_active_membership' => $state === 'member'])->save();

        return [$state => $person];
    });
    Http::fake(function ($r) use ($people) {
        if (str_contains($r->url(), '/users/')) {
            return Http::response(['id' => 'user_fresh_member', 'email' => $people['fresh_member']->email]);
        }
        if (str_contains($r->url(), '/organization_memberships')) {
            return Http::response(['data' => [['user_id' => 'user_fresh_member', 'organization_id' => 'org_current', 'status' => 'active']]]);
        }
        $person = $people->first(fn ($p) => $p->email === ($r->data()['email'] ?? null) || str_contains($r->url(), '/inv_source_'.$p->id.'/resend'));
        if ($r->method() === 'POST') {
            return Http::response(invitationFixture($person, 'pending', 'inv_result_'.$person->id));
        }
        $state = $people->search(fn ($p) => $p->id === $person->id);

        return Http::response(['data' => in_array($state, ['pending', 'expired', 'fresh_accepted']) ? [invitationFixture($person, $state === 'fresh_accepted' ? 'accepted' : $state, 'inv_source_'.$person->id)] : []]);
    });
    expect(app(QueueWorkosInvitations::class)->handle([], true))->toBe(7);
    expect(WorkosInvitationAttempt::orderBy('person_id')->pluck('person_id')->all())->toBe($people->only(['pending', 'expired', 'unverified', 'not_invited', 'fresh_accepted', 'fresh_member', 'null'])->pluck('id')->sort()->values()->all());
    WorkosInvitationAttempt::all()->each(fn ($attempt) => app(SendWorkosInvitation::class)->execute($attempt));
    expect(WorkosInvitationAttempt::where('person_id', $people['fresh_accepted']->id)->first()->reason)->toBe('already_accepted')->and(WorkosInvitationAttempt::where('person_id', $people['fresh_member']->id)->first()->reason)->toBe('current_member');
    $posts = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST');
    expect($posts)->toHaveCount(5, WorkosInvitationAttempt::get(['person_id', 'status', 'reason'])->toJson());
    foreach ($people->only(['pending', 'expired', 'unverified', 'not_invited', 'null']) as $state => $person) {
        $fresh = $person->fresh();
        $id = $state === 'pending' ? 'inv_source_'.$person->id : 'inv_result_'.$person->id;
        // Resend response may carry the provider's new ID; retain exactly the validated response.
        expect($fresh->workos_invitation_id)->toBe('inv_result_'.$person->id)->and($fresh->invitation_sent_at->equalTo($sent))->toBeTrue()->and(WorkosInvitationAttempt::where('person_id', $person->id)->first()->resulting_invitation_id)->toBe($fresh->workos_invitation_id);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && ($state === 'pending' ? str_ends_with($r->url(), '/'.$id.'/resend') : ($r->data()['email'] ?? null) === $person->email));
    }
});

it('prevents recovery for a contradictory accepted provider identity', function () {
    $person = User::factory()->create(['status' => 'invited', 'workos_user_id' => null]);
    app(QueueWorkosInvitations::class)->handle([$person->id]);
    Http::fake(fn ($r) => Http::response(str_contains($r->url(), '/users/') ? ['id' => 'user_wrong', 'email' => 'other@example.com'] : ['data' => [invitationFixture($person, 'accepted', 'inv_wrong', ['accepted_user_id' => 'user_wrong'])]]));
    app(SendWorkosInvitation::class)->execute(WorkosInvitationAttempt::first());
    expect(WorkosInvitationAttempt::first()->reason)->toBe('provider_verification_failed')->and($person->fresh()->first_access_at)->toBeNull()->and($person->fresh()->account_id)->toBeNull()->and($person->fresh()->status->value)->toBe('invited');
    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});
