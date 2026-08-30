<?php

use App\Enums\AssignmentStatus;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Department;
use App\Models\ModuleVersion;
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
    $department = Department::factory()->create();
    $nestedDepartment = Department::factory()->create();
    $marina = User::factory()->create(['name' => 'Marina Costa']);
    $marina->departments()->attach($department);
    $marina->managedDepartments()->attach($nestedDepartment);
    $nested = User::factory()->create(['name' => 'Nested Report']);
    $nested->departments()->attach($nestedDepartment);
    $outside = User::factory()->create(['name' => 'Outside Report']);
    UserTrainingAssignment::factory()->forCourse($course)->overdue(9)->create(['user_id' => $marina]);
    UserTrainingAssignment::factory()->forCourse($course)->overdue(9)->create(['user_id' => $nested]);
    UserTrainingAssignment::factory()->forCourse($course)->overdue(9)->create(['user_id' => $outside]);
    $manager = userWithPermissions([Permission::ComplianceDashboardView, Permission::AssignmentsView]);
    $manager->managedDepartments()->attach($department);

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('ui.workforce_status'))
        ->assertSee('Marina Costa')
        ->assertSee('Nested Report')
        ->assertDontSee('Outside Report');
});

it('links active people to every open training in the managers scope', function (): void {
    $course = assignableCourse();
    $department = Department::factory()->create();
    $nestedDepartment = Department::factory()->create();
    $direct = User::factory()->create(['name' => 'Direct Pending']);
    $direct->departments()->attach($department);
    $direct->managedDepartments()->attach($nestedDepartment);
    $nested = User::factory()->create(['name' => 'Nested In Progress']);
    $nested->departments()->attach($nestedDepartment);
    $outside = User::factory()->create(['name' => 'Outside Pending']);
    $completed = User::factory()->create(['name' => 'Completed Training']);
    $completed->departments()->attach($department);

    UserTrainingAssignment::factory()->forCourse($course)->create([
        'user_id' => $direct,
        'status' => AssignmentStatus::Pending,
        'due_at' => now()->addMonths(2),
    ]);
    UserTrainingAssignment::factory()->forCourse($course)->create([
        'user_id' => $nested,
        'status' => AssignmentStatus::InProgress,
        'due_at' => now()->addMonths(2),
    ]);
    UserTrainingAssignment::factory()->forCourse($course)->create([
        'user_id' => $outside,
        'status' => AssignmentStatus::Pending,
        'due_at' => now()->addMonths(2),
    ]);
    UserTrainingAssignment::factory()->forCourse($course)->create([
        'user_id' => $completed,
        'status' => AssignmentStatus::Completed,
        'completed_at' => now(),
    ]);

    $manager = userWithPermissions([Permission::ComplianceDashboardView, Permission::AssignmentsView]);
    $manager->managedDepartments()->attach($department);
    $openAssignmentsUrl = route('assignments.index', ['company' => currentCompany(), 'status' => 'open']);

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee($openAssignmentsUrl, escape: false);

    $this->actingAs($manager)
        ->get($openAssignmentsUrl)
        ->assertOk()
        ->assertSee('Direct Pending')
        ->assertSee('Nested In Progress')
        ->assertDontSee('Outside Pending')
        ->assertDontSee('Completed Training');
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
        ->get(route('my-training.show', ['assignment' => $assignment]))
        ->assertForbidden();
});

it('lets the assignee open their own assignment', function (): void {
    $user = employeeUser();
    $assignment = UserTrainingAssignment::factory()->forCourse(assignableCourse())->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('my-training.show', ['assignment' => $assignment]))
        ->assertOk()
        ->assertSee($assignment->course->title);
});

it('shows and opens frozen module compositions in an employee assignment', function (): void {
    $user = employeeUser();
    $course = assignableCourse();
    $version = $course->currentPublishedVersion;
    $module = ModuleVersion::factory()->published()->create([
        'title' => 'Structural steel safety',
        'course_version_id' => null,
    ]);
    CourseVersionModule::query()->create([
        'course_version_id' => $version->id,
        'module_version_id' => $module->id,
        'position' => 1,
        'is_required' => true,
    ]);
    $assignment = UserTrainingAssignment::factory()->forCourse($course)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('my-training.show', ['assignment' => $assignment]))
        ->assertOk()
        ->assertSee('Structural steel safety')
        ->assertDontSee(__('ui.no_lessons'));

    $this->actingAs($user)
        ->get(route('my-training.lesson', ['assignment' => $assignment, 'lesson' => $module]))
        ->assertOk()
        ->assertSee('Structural steel safety');
});

it('lets an operator open an assignment for review', function (): void {
    $department = Department::factory()->create();
    $person = User::factory()->create();
    $person->departments()->attach($department);
    $assignment = UserTrainingAssignment::factory()->forCourse(assignableCourse())->create([
        'user_id' => $person,
    ]);
    $manager = userWithPermissions([Permission::AssignmentsView]);
    $manager->managedDepartments()->attach($department);

    $this->actingAs($manager)
        ->get(route('my-training.show', ['assignment' => $assignment]))
        ->assertOk();
});
