<?php

use App\Actions\Courses\MakeCourseShared;
use App\Models\Account;
use App\Models\Certificate;
use App\Models\ComplianceEvent;
use App\Models\Course;
use App\Models\CourseAttempt;
use App\Models\CourseVersion;
use App\Models\UserTrainingAssignment;
use App\Services\Courses\CoursePromotionImpact;

it('preserves historical version assignment attempt and certificate references during promotion', function (): void {
    $company = currentCompany();
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);
    $assignment = UserTrainingAssignment::factory()->forCourse($course)->completed()->create();
    $attempt = CourseAttempt::factory()->create(['assignment_id' => $assignment->id, 'course_version_id' => $version->id]);
    $certificate = Certificate::factory()->create([
        'user_id' => $assignment->user_id,
        'assignment_id' => $assignment->id,
        'course_id' => $course->id,
        'course_version_id' => $version->id,
    ]);
    $event = ComplianceEvent::factory()->create([
        'user_id' => $assignment->user_id,
        'assignment_id' => $assignment->id,
        'course_version_id' => $version->id,
        'course_attempt_id' => $attempt->id,
    ]);
    $token = app(CoursePromotionImpact::class)->preview($course, $company)['token'];

    app(MakeCourseShared::class)->handle($course, $company, Account::factory()->platformAdmin()->create(), $token);

    expect($version->fresh()->course_id)->toBe($course->id)
        ->and($assignment->fresh()->course_id)->toBe($course->id)
        ->and($assignment->fresh()->course_version_id)->toBe($version->id)
        ->and($attempt->fresh()->course_version_id)->toBe($version->id)
        ->and($certificate->fresh()->course_id)->toBe($course->id)
        ->and($certificate->fresh()->course_version_id)->toBe($version->id)
        ->and($event->fresh()->assignment_id)->toBe($assignment->id)
        ->and($event->fresh()->course_version_id)->toBe($version->id);
});
