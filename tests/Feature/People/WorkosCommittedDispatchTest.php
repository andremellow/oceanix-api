<?php

use App\Actions\People\QueueWorkosInvitations;
use App\Actions\People\QueueWorkosSynchronization;
use App\Enums\Permission;
use App\Enums\WorkosOperationStatus;
use App\Jobs\ReconcileWorkosInvitations;
use App\Jobs\SendWorkosInvitation;
use App\Models\Account;
use App\Models\User;
use App\Models\WorkosInvitationAttempt;
use App\Models\WorkosSyncRun;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    currentCompany()->update(['workos_organization_id' => 'org_dispatch']);
    $actor = userWithPermissions([Permission::PeopleInvite, Permission::PeopleSyncWorkos]);
    $actor->forceFill(['account_id' => Account::factory()->create()->id])->save();
    $this->actingAs($actor);
    Http::fake();
});

it('dispatches exact scalar jobs once after the committed transaction boundary', function () {
    Queue::fake();
    $person = User::factory()->create();
    DB::transaction(function () use ($person) {
        app(QueueWorkosInvitations::class)->handle([$person->id]);
        app(QueueWorkosSynchronization::class)->handle();
        Queue::assertNothingPushed();
    });
    $attempt = WorkosInvitationAttempt::firstOrFail();
    $run = WorkosSyncRun::firstOrFail();
    Queue::assertPushed(SendWorkosInvitation::class, fn ($job) => $job->companyId === currentCompany()->id && $job->personId === $person->id && $job->initiatedBy === auth()->id() && $job->attemptId === $attempt->id);
    Queue::assertPushed(ReconcileWorkosInvitations::class, fn ($job) => $job->companyId === currentCompany()->id && $job->actorId === auth()->id() && $job->runId === $run->id);
    app(QueueWorkosInvitations::class)->handle([$person->id]);
    app(QueueWorkosSynchronization::class)->handle();
    Queue::assertPushed(SendWorkosInvitation::class, 1);
    Queue::assertPushed(ReconcileWorkosInvitations::class, 1);
    Http::assertNothingSent();
});

it('persists executable database queue work for accepted operations', function () {
    config(['queue.default' => 'database']);
    $person = User::factory()->create();
    DB::transaction(fn () => app(QueueWorkosInvitations::class)->handle([$person->id]));
    $run = DB::transaction(fn () => app(QueueWorkosSynchronization::class)->handle());
    $payloads = DB::table('jobs')->pluck('payload')->map(fn ($payload) => unserialize(json_decode($payload, true)['data']['command']));
    expect($payloads)->toHaveCount(2)->and($payloads->filter(fn ($job) => $job instanceof SendWorkosInvitation && $job->attemptId === WorkosInvitationAttempt::first()->id && $job->personId === $person->id))->toHaveCount(1)->and($payloads->filter(fn ($job) => $job instanceof ReconcileWorkosInvitations && $job->runId === $run->id))->toHaveCount(1);
    Http::assertNothingSent();
});

it('makes suppressed unique dispatch terminal and permits later recovery', function () {
    Queue::fake();
    $person = User::factory()->create();
    $lock = new UniqueLock(Cache::store());
    $send = new SendWorkosInvitation(currentCompany()->id, $person->id, auth()->id(), 999);
    $sync = new ReconcileWorkosInvitations(currentCompany()->id, auth()->id(), 999);
    expect($lock->acquire($send))->toBeTrue()->and($lock->acquire($sync))->toBeTrue();
    app(QueueWorkosInvitations::class)->handle([$person->id]);
    $run = app(QueueWorkosSynchronization::class)->handle();
    expect(WorkosInvitationAttempt::first()->status)->toBe(WorkosOperationStatus::Failed)->and(WorkosInvitationAttempt::first()->reason)->toBe('queue_unavailable')->and($run->fresh()->reason)->toBe('queue_unavailable')->and($run->fresh()->status)->toBe(WorkosOperationStatus::Failed);
    Queue::assertNothingPushed();
    $lock->release($send);
    $lock->release($sync);
    expect(app(QueueWorkosInvitations::class)->handle([$person->id]))->toBe(1);
    expect(app(QueueWorkosSynchronization::class)->handle()->id)->not->toBe($run->id);
    Queue::assertPushed(SendWorkosInvitation::class, 1);
    Queue::assertPushed(ReconcileWorkosInvitations::class, 1);
});

it('records thrown dispatch failures and releases the unique lock', function () {
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->twice()->andThrow(new RuntimeException('queue offline'));
    $person = User::factory()->create();
    app(QueueWorkosInvitations::class)->handle([$person->id]);
    $run = app(QueueWorkosSynchronization::class)->handle();
    expect(WorkosInvitationAttempt::first()->reason)->toBe('queue_unavailable')->and($run->fresh()->reason)->toBe('queue_unavailable');
    $lock = new UniqueLock(Cache::store());
    expect($lock->acquire(new SendWorkosInvitation(currentCompany()->id, $person->id, auth()->id(), 999)))->toBeTrue();
    Http::assertNothingSent();
});
