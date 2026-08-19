<?php

use App\Enums\ComplianceEventType;
use App\Models\ComplianceEvent;
use App\Models\User;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Support\Str;

it('records an event with both timestamps', function (): void {
    $user = User::factory()->create();

    $event = app(ComplianceEventRecorder::class)->record(
        ComplianceEventType::CourseStarted,
        $user->id,
        ['occurred_at' => now()->subMinutes(3)],
    );

    expect($event->occurred_at->lt($event->received_at))->toBeTrue()
        ->and($event->uuid)->not->toBeEmpty();
});

it('is idempotent on the client generated uuid', function (): void {
    $user = User::factory()->create();
    $uuid = (string) Str::uuid();
    $recorder = app(ComplianceEventRecorder::class);

    $first = $recorder->record(ComplianceEventType::VideoPlayed, $user->id, ['uuid' => $uuid]);
    $second = $recorder->record(ComplianceEventType::VideoPlayed, $user->id, ['uuid' => $uuid]);

    expect($second->id)->toBe($first->id)
        ->and(ComplianceEvent::query()->count())->toBe(1);
});

it('never lets a client submit a server-authored event type', function (): void {
    $user = User::factory()->create();

    $recorded = app(ComplianceEventRecorder::class)->ingestBatch($user->id, [
        ['event_type' => ComplianceEventType::VideoPlayed->value, 'uuid' => (string) Str::uuid()],
        ['event_type' => ComplianceEventType::CourseCompleted->value, 'uuid' => (string) Str::uuid()],
        ['event_type' => ComplianceEventType::CertificateIssued->value, 'uuid' => (string) Str::uuid()],
        ['event_type' => 'made.up.event', 'uuid' => (string) Str::uuid()],
    ]);

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]->event_type)->toBe(ComplianceEventType::VideoPlayed);
});

it('takes the received timestamp from the server even when the device clock is wrong', function (): void {
    $user = User::factory()->create();

    $event = app(ComplianceEventRecorder::class)->record(
        ComplianceEventType::VideoProgressed,
        $user->id,
        ['occurred_at' => now()->addYear(), 'received_at' => now()->addYear()],
    );

    expect($event->received_at->isToday())->toBeTrue()
        ->and($event->hasClockSkew())->toBeTrue();
});

it('refuses to update or delete recorded evidence', function (): void {
    $event = ComplianceEvent::factory()->create();

    $event->update(['position_seconds' => 42]);
    $event->delete();

    $stored = ComplianceEvent::query()->find($event->id);

    expect($stored)->not->toBeNull()
        ->and($stored->position_seconds)->toBeNull();
});

it('replays an interrupted sync batch without duplicating anything', function (): void {
    $user = User::factory()->create();
    $recorder = app(ComplianceEventRecorder::class);

    $batch = collect(range(1, 5))->map(fn (int $sequence): array => [
        'event_type' => ComplianceEventType::VideoProgressed->value,
        'uuid' => (string) Str::uuid(),
        'client_sequence' => $sequence,
        'position_seconds' => $sequence * 30,
    ])->all();

    $recorder->ingestBatch($user->id, $batch);
    $recorder->ingestBatch($user->id, $batch);

    expect(ComplianceEvent::query()->count())->toBe(5);
});
