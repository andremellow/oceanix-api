<?php

use App\Actions\People\QueueWorkosInvitations;
use App\Actions\People\SendWorkosInvitation;
use App\Enums\Permission;
use App\Enums\WorkosInvitationState;
use App\Enums\WorkosOperationStatus;
use App\Models\Account;
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
