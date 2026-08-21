<?php

use App\Enums\ComplianceEventType;
use App\Enums\Permission;
use App\Services\Compliance\AssignmentEvidence;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(fn () => fakeCloudflarePlayback());

it('shows how many times each part of the video was watched', function (): void {
    [$assignment, $lesson] = trainableAssignment();
    $recorder = app(ComplianceEventRecorder::class);
    $clock = Carbon::now();

    // 0→60, rewind to 20, watch 20→60 again.
    foreach ([0, 20, 40, 60, 20, 40, 60] as $position) {
        $clock = $clock->copy()->addSeconds(25);
        Carbon::setTestNow($clock);

        $recorder->record(ComplianceEventType::VideoProgressed, $assignment->user_id, [
            'uuid' => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'lesson_id' => $lesson->id,
            'position_seconds' => $position,
        ]);
    }

    $map = app(AssignmentEvidence::class)->watchMap($assignment, $lesson->fresh());
    $times = fn (int $second): int => collect($map['buckets'])
        ->first(fn (array $bucket): bool => $second >= $bucket['from'] && $second < $bucket['to'])['times'];

    expect($times(10))->toBe(1)
        ->and($times(30))->toBe(2)
        ->and($times(80))->toBe(0)
        ->and($map['percentage'])->toBe(60);
});

it('leaves an implausible jump out of the coverage map', function (): void {
    [$assignment, $lesson] = trainableAssignment();
    $recorder = app(ComplianceEventRecorder::class);

    $recorder->record(ComplianceEventType::VideoPlayed, $assignment->user_id, [
        'assignment_id' => $assignment->id, 'lesson_id' => $lesson->id, 'position_seconds' => 0,
    ]);
    Carbon::setTestNow(Carbon::now()->addSecond());
    $recorder->record(ComplianceEventType::VideoProgressed, $assignment->user_id, [
        'assignment_id' => $assignment->id, 'lesson_id' => $lesson->id, 'position_seconds' => 100,
    ]);

    expect(app(AssignmentEvidence::class)->watchMap($assignment, $lesson->fresh())['percentage'])->toBe(0);
});

it('opens the drill-down for someone who can view assignments', function (): void {
    [$assignment] = trainableAssignment();

    $this->actingAs(userWithPermissions([Permission::AssignmentsView]))
        ->get(route('assignments.show', ['assignment' => $assignment]))
        ->assertOk()
        ->assertSee($assignment->user->name)
        ->assertSee(__('ui.watch_evidence'));
});

it('hides the raw trail from someone without the evidence permission', function (): void {
    [$assignment] = trainableAssignment();

    $this->actingAs(userWithPermissions([Permission::AssignmentsView]))
        ->get(route('assignments.show', ['assignment' => $assignment]))
        ->assertOk()
        ->assertDontSee(__('ui.evidence_trail'));

    $this->actingAs(userWithPermissions([Permission::ComplianceEventsView]))
        ->get(route('assignments.show', ['assignment' => $assignment]))
        ->assertOk()
        ->assertSee(__('ui.evidence_trail'));
});

it('denies the drill-down to an employee looking at someone else', function (): void {
    [$assignment] = trainableAssignment();

    $this->actingAs(employeeUser())
        ->get(route('assignments.show', ['assignment' => $assignment]))
        ->assertForbidden();
});
