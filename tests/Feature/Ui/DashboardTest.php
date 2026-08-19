<?php

use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\User;
use App\Models\UserTrainingAssignment;

function assignableCourse(): Course
{
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);

    return $course->refresh();
}

it('shows the compliance overview to an operator', function (): void {
    $course = assignableCourse();
    UserTrainingAssignment::factory()->forCourse($course)->overdue(9)->create([
        'user_id' => User::factory()->create(['name' => 'Marina Costa']),
    ]);

    $this->actingAs(userWithPermissions([Permission::ComplianceDashboardView, Permission::AssignmentsView]))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('ui.workforce_status'))
        ->assertSee('Marina Costa');
});

it('hides the compliance overview from an employee', function (): void {
    $this->actingAs(employeeUser())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(__('ui.workforce_status'))
        ->assertSee(__('ui.employee_summary'));
});

it('warns an employee about their own overdue training', function (): void {
    $user = employeeUser();
    UserTrainingAssignment::factory()->forCourse(assignableCourse())->overdue(4)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('ui.resolve_now'));
});

it('never shows another person assignments on the employee board', function (): void {
    $user = employeeUser();
    $course = assignableCourse();

    UserTrainingAssignment::factory()->forCourse($course)->create(['user_id' => $user->id]);
    UserTrainingAssignment::factory()->forCourse($course)->create([
        'user_id' => User::factory()->create(['name' => 'Someone Else']),
    ]);

    $this->actingAs($user)
        ->get(route('my-training'))
        ->assertOk()
        ->assertDontSee('Someone Else');
});

it('denies opening an assignment that belongs to someone else', function (): void {
    $assignment = UserTrainingAssignment::factory()->forCourse(assignableCourse())->create([
        'user_id' => User::factory(),
    ]);

    $this->actingAs(employeeUser())
        ->get(route('my-training.show', $assignment))
        ->assertForbidden();
});

it('lets the assignee open their own assignment', function (): void {
    $user = employeeUser();
    $assignment = UserTrainingAssignment::factory()->forCourse(assignableCourse())->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('my-training.show', $assignment))
        ->assertOk()
        ->assertSee($assignment->course->title);
});

it('lets an operator open an assignment for review', function (): void {
    $assignment = UserTrainingAssignment::factory()->forCourse(assignableCourse())->create([
        'user_id' => User::factory(),
    ]);

    $this->actingAs(userWithPermissions([Permission::AssignmentsView]))
        ->get(route('my-training.show', $assignment))
        ->assertOk();
});
