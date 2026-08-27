<?php

use App\Actions\Courses\AssociateSharedCourse;
use App\Actions\Courses\RemoveSharedCourse;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyCourse;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\TrainingRequirement;
use App\Models\UserTrainingAssignment;
use App\Services\Courses\CompanyCourseLibrary;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

function publishedSharedCourseForAssociation(): Course
{
    $course = Course::factory()->shared()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);

    return $course;
}

it('associates idempotently and reactivates the durable company-course pair', function (): void {
    $actor = userWithPermissions([Permission::SharedCoursesAdd]);
    $course = publishedSharedCourseForAssociation();
    $action = app(AssociateSharedCourse::class);

    $first = $action->handle($course, $actor);
    $second = $action->handle($course, $actor);

    expect($second->is($first))->toBeTrue()
        ->and(CompanyCourse::query()->where('course_id', $course->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'shared_course.associated')->count())->toBe(1);

    $first->update(['removed_at' => now(), 'removal_reason' => 'Temporary']);
    $reactivated = $action->handle($course, $actor);

    expect($reactivated->removed_at)->toBeNull()
        ->and(CompanyCourse::query()->where('course_id', $course->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'shared_course.associated')->count())->toBe(2);
});

it('rejects private unpublished and cross-tenant association attempts', function (): void {
    $actor = userWithPermissions([Permission::SharedCoursesAdd]);
    $private = Course::factory()->create();
    $unpublished = Course::factory()->shared()->draft()->create();

    expect(fn () => app(AssociateSharedCourse::class)->handle($private, $actor))->toThrow(DomainException::class)
        ->and(fn () => app(AssociateSharedCourse::class)->handle($unpublished, $actor))->toThrow(DomainException::class);

    $other = Company::factory()->create();
    app(TenantContext::class)->set($other);

    expect(fn () => app(AssociateSharedCourse::class)->handle(publishedSharedCourseForAssociation(), $actor))
        ->toThrow(AuthorizationException::class);
});

it('blocks removal for active requirements and permits an audited removal after blockers clear', function (): void {
    $actor = userWithPermissions([Permission::SharedCoursesAdd, Permission::SharedCoursesRemove]);
    $course = publishedSharedCourseForAssociation();
    $association = app(AssociateSharedCourse::class)->handle($course, $actor);
    $requirement = TrainingRequirement::factory()->create(['course_id' => $course->id]);

    expect(fn () => app(RemoveSharedCourse::class)->handle($association, $actor, 'No longer needed'))
        ->toThrow(DomainException::class);

    $requirement->update(['status' => 'retired']);
    $assignment = UserTrainingAssignment::factory()->forCourse($course)->create();

    expect(fn () => app(RemoveSharedCourse::class)->handle($association, $actor, 'No longer needed'))
        ->toThrow(DomainException::class);

    $assignment->update(['status' => 'completed', 'completed_at' => now()]);
    $removed = app(RemoveSharedCourse::class)->handle($association, $actor, 'No longer needed');

    expect($removed->removed_at)->not->toBeNull()
        ->and($removed->removal_reason)->toBe('No longer needed')
        ->and(AuditLog::query()->where('action', 'shared_course.removed')->count())->toBe(1);

    app(RemoveSharedCourse::class)->handle($removed, $actor, 'Repeated request');
    expect(AuditLog::query()->where('action', 'shared_course.removed')->count())->toBe(1);
});

it('does not leak one company shared library association into another company', function (): void {
    $firstCompany = currentCompany();
    $actor = userWithPermissions([Permission::SharedCoursesAdd]);
    $course = publishedSharedCourseForAssociation();
    app(AssociateSharedCourse::class)->handle($course, $actor);

    $secondCompany = Company::factory()->create();

    expect(app(CompanyCourseLibrary::class)->sharedCourses($firstCompany)->modelKeys())->toBe([$course->id])
        ->and(app(CompanyCourseLibrary::class)->sharedCourses($secondCompany))->toBeEmpty();
});
