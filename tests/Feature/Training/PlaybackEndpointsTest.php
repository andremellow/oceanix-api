<?php

use App\Enums\ComplianceEventType;
use App\Models\ComplianceEvent;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** The provider is exercised through its real implementation; only the network is faked. */
function fakeCloudflarePlayback(): void
{
    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['token' => 'signed-token']]),
    ]);
}

function eventPayload(array $overrides = []): array
{
    return [
        'uuid' => (string) Str::uuid(),
        'event_type' => ComplianceEventType::VideoProgressed->value,
        'occurred_at' => now()->toIso8601String(),
        'position_seconds' => 30,
        ...$overrides,
    ];
}

it('ingests player events for the assignee', function (): void {
    [$assignment, $lesson] = trainableAssignment();

    $this->actingAs($assignment->user)
        ->postJson(route('my-training.events', [$assignment, $lesson]), ['events' => [eventPayload()]])
        ->assertOk()
        ->assertJsonStructure(['percentage_watched', 'assessment_unlocked']);

    expect(ComplianceEvent::query()->where('assignment_id', $assignment->id)->count())->toBe(1);
});

it('rejects a batch from anyone but the assignee', function (): void {
    [$assignment, $lesson] = trainableAssignment();

    $this->actingAs(User::factory()->create())
        ->postJson(route('my-training.events', [$assignment, $lesson]), ['events' => [eventPayload()]])
        ->assertForbidden();

    expect(ComplianceEvent::query()->count())->toBe(0);
});

it('rejects server-authored event types submitted by a client', function (): void {
    [$assignment, $lesson] = trainableAssignment();

    $this->actingAs($assignment->user)
        ->postJson(route('my-training.events', [$assignment, $lesson]), [
            'events' => [eventPayload(['event_type' => ComplianceEventType::CourseCompleted->value])],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('events.0.event_type');
});

it('binds every event to the assignment in the URL, not the payload', function (): void {
    [$assignment, $lesson] = trainableAssignment();
    [$other] = trainableAssignment();

    $this->actingAs($assignment->user)
        ->postJson(route('my-training.events', [$assignment, $lesson]), [
            'events' => [eventPayload(['assignment_id' => $other->id, 'lesson_id' => 999999])],
        ])
        ->assertOk();

    $event = ComplianceEvent::query()->firstOrFail();

    expect($event->assignment_id)->toBe($assignment->id)
        ->and($event->lesson_id)->toBe($lesson->id);
});

it('ignores a replayed batch', function (): void {
    [$assignment, $lesson] = trainableAssignment();
    $payload = ['events' => [eventPayload(), eventPayload()]];

    $this->actingAs($assignment->user)->postJson(route('my-training.events', [$assignment, $lesson]), $payload)->assertOk();
    $this->actingAs($assignment->user)->postJson(route('my-training.events', [$assignment, $lesson]), $payload)->assertOk();

    expect(ComplianceEvent::query()->count())->toBe(2);
});

it('refuses a lesson from another course version', function (): void {
    [$assignment] = trainableAssignment();
    $foreign = Lesson::factory()->create();

    $this->actingAs($assignment->user)
        ->postJson(route('my-training.events', [$assignment, $foreign]), ['events' => [eventPayload()]])
        ->assertNotFound();
});

it('mints playback only for the assignee', function (): void {
    fakeCloudflarePlayback();
    [$assignment, $lesson] = trainableAssignment();

    $this->actingAs($assignment->user)
        ->postJson(route('my-training.playback', [$assignment, $lesson]))
        ->assertOk()
        ->assertJsonStructure(['url', 'expires_in']);

    $this->actingAs(User::factory()->create())
        ->postJson(route('my-training.playback', [$assignment, $lesson]))
        ->assertForbidden();
});

it('records the playback authorization as evidence', function (): void {
    fakeCloudflarePlayback();
    [$assignment, $lesson] = trainableAssignment();

    $this->actingAs($assignment->user)
        ->postJson(route('my-training.playback', [$assignment, $lesson]))
        ->assertOk();

    expect(ComplianceEvent::query()
        ->where('event_type', ComplianceEventType::PlaybackAuthorized->value)
        ->where('assignment_id', $assignment->id)
        ->exists())->toBeTrue();
});
