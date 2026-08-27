<?php

use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseVersion;

function publishedSharedCourseForAccess(): Course
{
    $course = Course::factory()->shared()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);

    return $course;
}

it('requires the exact view permission for direct shared catalog access', function (): void {
    $company = currentCompany();

    $this->actingAs(employeeUser())->get(route('shared-courses.index', ['company' => $company]))->assertForbidden();
    $this->actingAs(userWithPermissions([Permission::SharedCoursesView]))
        ->get(route('shared-courses.index', ['company' => $company]))->assertOk();
});

it('expands add and remove prerequisites and supports revocation', function (): void {
    $user = userWithPermissions([Permission::SharedCoursesAdd, Permission::SharedCoursesRemove]);

    expect($user->can(Permission::SharedCoursesAdd->value))->toBeTrue()
        ->and($user->can(Permission::SharedCoursesRemove->value))->toBeTrue()
        ->and($user->can(Permission::SharedCoursesView->value))->toBeTrue()
        ->and($user->can(Permission::CoursesView->value))->toBeTrue();

    $user->roles()->where('is_protected', false)->each(fn ($role) => $role->permissions()->detach());

    expect($user->can(Permission::SharedCoursesAdd->value))->toBeFalse()
        ->and($user->can(Permission::SharedCoursesRemove->value))->toBeFalse();
});

it('keeps the tenant administrator permission bypass for shared catalog actions', function (): void {
    $company = currentCompany();
    $course = publishedSharedCourseForAccess();

    $this->actingAs(adminUser())
        ->get(route('shared-courses.show', ['company' => $company, 'course' => $course]))
        ->assertOk();
});
