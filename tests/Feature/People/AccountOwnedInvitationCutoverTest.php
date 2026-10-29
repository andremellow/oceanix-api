<?php

use App\Enums\WorkosOperationStatus;
use App\Jobs\ReconcileWorkosInvitations;
use App\Jobs\SendWorkosInvitation;
use App\Models\WorkosInvitationAttempt;
use App\Models\WorkosSyncRun;
use App\Tenancy\TenantContext;
use Database\Factories\CompanyFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('SC-22 quarantines serialized legacy send and sync jobs without any external request and restores tenant context', function () {
    Http::preventStrayRequests();
    $company = currentCompany();
    $actor = adminUser();
    $person = employeeUser();
    $attempt = WorkosInvitationAttempt::create(['person_id' => $person->id, 'actor_id' => $actor->id, 'mode' => 'selected', 'generation' => 0, 'status' => WorkosOperationStatus::Queued]);
    $run = WorkosSyncRun::create(['actor_id' => $actor->id, 'status' => WorkosOperationStatus::Queued]);
    $other = CompanyFactory::new()->create();
    app(TenantContext::class)->set($other);
    (new SendWorkosInvitation($company->id, $person->id, $actor->id, $attempt->id))->handle(app(App\Actions\People\SendWorkosInvitation::class));
    (new ReconcileWorkosInvitations($company->id, $actor->id, $run->id))->handle(app(App\Actions\People\ReconcileWorkosInvitations::class));
    expect($attempt->fresh()->reason)->toBe('legacy_cutover_review')->and($run->fresh()->reason)->toBe('legacy_cutover_review')->and(app(TenantContext::class)->id())->toBe($other->id);
    Http::assertNothingSent();
});

it('TA-05 previews and quarantines persisted legacy work without losing dispatch evidence', function () {
    Queue::fake();
    Http::preventStrayRequests();
    $actor = adminUser();
    $person = employeeUser();
    $attempts = [];
    foreach (['queued', 'running', 'sending'] as $status) {
        $attempts[] = WorkosInvitationAttempt::create(['person_id' => $person->id, 'actor_id' => $actor->id, 'mode' => 'selected', 'generation' => 4, 'status' => $status, 'send_started_at' => $status === 'sending' ? now() : null]);
    }
    foreach (['queued', 'running'] as $status) {
        WorkosSyncRun::create(['actor_id' => $actor->id, 'status' => $status]);
    }
    $before = WorkosInvitationAttempt::orderBy('id')->get()->toJson();
    $runs = WorkosSyncRun::orderBy('id')->get()->toJson();
    $this->artisan('account:quarantine-legacy-invitations')->expectsOutput('Legacy invitation attempts: 3; synchronization runs: 2')->assertSuccessful();
    expect(WorkosInvitationAttempt::orderBy('id')->get()->toJson())->toBe($before)->and(WorkosSyncRun::orderBy('id')->get()->toJson())->toBe($runs);
    $this->artisan('account:quarantine-legacy-invitations', ['--apply' => true])->assertSuccessful();
    expect(WorkosInvitationAttempt::where('status', 'delivery_unconfirmed')->count())->toBe(3)->and(WorkosSyncRun::where('status', 'failed')->count())->toBe(2)->and($attempts[2]->fresh()->send_started_at)->not->toBeNull()->and($attempts[2]->fresh()->generation)->toBe(4);
    $this->artisan('account:quarantine-legacy-invitations', ['--apply' => true])->expectsOutput('Legacy invitation attempts: 0; synchronization runs: 0')->assertSuccessful();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
