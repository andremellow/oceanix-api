<?php

use App\Actions\Assignments\ReplaceAssignmentsForPublication;
use App\Actions\Training\StartAssignment;
use App\Enums\AssignmentStatus;
use App\Enums\ComplianceEventType;
use App\Enums\NotificationType;
use App\Models\Account;
use App\Models\ComplianceEvent;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\ScheduledNotification;
use App\Models\UserTrainingAssignment;

function publicationVersions(): array
{
    $course = Course::factory()->shared()->create();
    $previous = CourseVersion::factory()->published()->create(['course_id' => $course->id, 'version_number' => 1]);
    $published = CourseVersion::factory()->published()->create(['course_id' => $course->id, 'version_number' => 2]);
    $course->update(['current_published_version_id' => $published->id]);

    return [$previous, $published];
}

it('replaces unstarted assignments idempotently while preserving obligation data', function (): void {
    [$previous, $published] = publicationVersions();
    $actor = Account::factory()->platformAdmin()->create();
    $assignment = UserTrainingAssignment::factory()->create([
        'course_id' => $previous->course_id,
        'course_version_id' => $previous->id,
        'origin_id' => 'manual-42',
        'series_key' => 'annual-safety',
        'cycle_number' => 4,
        'metadata' => ['keep' => 'this'],
    ]);
    $notification = ScheduledNotification::query()->create([
        'user_id' => $assignment->user_id,
        'assignment_id' => $assignment->id,
        'type' => NotificationType::DueSoon,
        'scheduled_for' => now()->toDateString(),
    ]);

    $action = app(ReplaceAssignmentsForPublication::class);
    expect($action->handle($previous, $published, $actor))->toBe(1)
        ->and($action->handle($previous, $published, $actor))->toBe(0);

    $replacement = UserTrainingAssignment::query()->where('supersedes_assignment_id', $assignment->id)->sole();
    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Cancelled)
        ->and($replacement->status)->toBe(AssignmentStatus::Pending)
        ->and($replacement->course_version_id)->toBe($published->id)
        ->and($replacement->cycle_number)->toBe(4)
        ->and($replacement->replacement_generation)->toBe(1)
        ->and($replacement->metadata['keep'])->toBe('this')
        ->and($notification->fresh()->assignment_id)->toBe($replacement->id)
        ->and(ComplianceEvent::query()->whereIn('event_type', [
            ComplianceEventType::AssignmentCancelled->value,
            ComplianceEventType::AssignmentCreated->value,
        ])->count())->toBe(2);
});

it('keeps started assignments unless restart is explicitly selected', function (): void {
    [$previous, $published] = publicationVersions();
    $actor = Account::factory()->platformAdmin()->create();
    $assignment = UserTrainingAssignment::factory()->create([
        'course_id' => $previous->course_id, 'course_version_id' => $previous->id,
    ]);
    app(StartAssignment::class)->handle($assignment);

    expect(app(ReplaceAssignmentsForPublication::class)->handle($previous, $published, $actor))->toBe(0)
        ->and($assignment->fresh()->status)->toBe(AssignmentStatus::InProgress)
        ->and(app(ReplaceAssignmentsForPublication::class)->handle($previous, $published, $actor, true))->toBe(1)
        ->and($assignment->fresh()->status)->toBe(AssignmentStatus::Cancelled);
});

it('resolves a cancelled predecessor to its replacement when a start races publication', function (): void {
    [$previous, $published] = publicationVersions();
    $actor = Account::factory()->platformAdmin()->create();
    $assignment = UserTrainingAssignment::factory()->create([
        'course_id' => $previous->course_id, 'course_version_id' => $previous->id,
    ]);
    app(ReplaceAssignmentsForPublication::class)->handle($previous, $published, $actor);

    $attempt = app(StartAssignment::class)->handle($assignment->fresh());

    expect($attempt->assignment->supersedes_assignment_id)->toBe($assignment->id)
        ->and($attempt->course_version_id)->toBe($published->id);
});

it('never migrates completed or waived obligations', function (AssignmentStatus $status): void {
    [$previous, $published] = publicationVersions();
    $assignment = UserTrainingAssignment::factory()->create([
        'course_id' => $previous->course_id, 'course_version_id' => $previous->id, 'status' => $status,
    ]);

    expect(app(ReplaceAssignmentsForPublication::class)->handle(
        $previous, $published, Account::factory()->platformAdmin()->create(), true,
    ))->toBe(0)->and($assignment->fresh()->status)->toBe($status);
})->with([AssignmentStatus::Completed, AssignmentStatus::Waived]);
