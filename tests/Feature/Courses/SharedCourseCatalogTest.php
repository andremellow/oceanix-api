<?php

use App\Actions\Courses\AssociateSharedCourse;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseVersion;

function publishedSharedCourseForCatalog(array $attributes = []): Course
{
    $course = Course::factory()->shared()->create($attributes);
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);

    return $course;
}

it('shows shared ownership labels and the add action only with its exact permission', function (): void {
    $course = publishedSharedCourseForCatalog(['title' => 'Global Sea Survival']);
    $viewer = userWithPermissions([Permission::SharedCoursesView]);

    Livewire\Livewire::actingAs($viewer)->test('courses.shared-index')
        ->assertSee('Global Sea Survival')->assertSee(__('Shared'))->assertSee(__('Managed by platform'))
        ->assertDontSee(__('Add to Company'));

    Livewire\Livewire::actingAs(userWithPermissions([Permission::SharedCoursesAdd]))
        ->test('courses.shared-index')->assertSee(__('Add to Company'));
});

it('moves an associated course from the eligible catalog into the company library', function (): void {
    $course = publishedSharedCourseForCatalog(['title' => 'Shared HUET']);
    $actor = userWithPermissions([Permission::SharedCoursesAdd]);
    app(AssociateSharedCourse::class)->handle($course, $actor);

    Livewire\Livewire::actingAs($actor)->test('courses.shared-index')->assertDontSee('Shared HUET');
    Livewire\Livewire::actingAs($actor)->test('courses.index')
        ->assertSee(__('Shared Courses'))->assertSee('Shared HUET')->assertSee(__('Managed by platform'));
});

it('renders a specific empty state when no published shared course is eligible', function (): void {
    $viewer = userWithPermissions([Permission::SharedCoursesView]);

    Livewire\Livewire::actingAs($viewer)->test('courses.shared-index')
        ->assertSee(__('No shared courses available'));
});
