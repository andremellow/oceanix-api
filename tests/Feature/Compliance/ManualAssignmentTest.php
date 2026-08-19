<?php

use App\Actions\Assignments\CreateManualAssignment;
use App\Enums\AssignmentOrigin;
use App\Enums\ComplianceEventType;
use App\Models\AuditLog;
use App\Models\ComplianceEvent;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\User;

it('creates an assignment without a requirement and freezes the published version', function (): void {
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);

    $user = User::factory()->create();

    $assignment = app(CreateManualAssignment::class)->handle(
        $user,
        $course->refresh(),
        dueAt: now()->addDays(15),
    );

    expect($assignment->training_requirement_id)->toBeNull()
        ->and($assignment->origin_type)->toBe(AssignmentOrigin::Manual)
        ->and($assignment->course_version_id)->toBe($version->id);

    // A newer published version must not retroactively change the obligation.
    $newer = CourseVersion::factory()->published()->create([
        'course_id' => $course->id,
        'version_number' => 2,
    ]);
    $course->update(['current_published_version_id' => $newer->id]);

    expect($assignment->fresh()->course_version_id)->toBe($version->id);
});

it('records evidence and an audit entry when an assignment is created', function (): void {
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);

    app(CreateManualAssignment::class)->handle(User::factory()->create(), $course->refresh());

    expect(ComplianceEvent::query()->where('event_type', ComplianceEventType::AssignmentCreated->value)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'assignment.created')->count())->toBe(1);
});

it('refuses to assign a course with no published version', function (): void {
    $course = Course::factory()->draft()->create();

    expect(fn () => app(CreateManualAssignment::class)->handle(User::factory()->create(), $course))
        ->toThrow(RuntimeException::class);
});
