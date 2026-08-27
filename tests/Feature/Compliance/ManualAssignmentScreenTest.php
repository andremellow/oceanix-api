<?php

use App\Enums\AssignmentOrigin;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Department;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use Livewire\Livewire;

function publishedManualAssignmentCourse(): Course
{
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course]);
    $course->update(['current_published_version_id' => $version->id]);

    return $course->refresh();
}

it('shows the current administrator and every other active company user in the assignment picker', function (): void {
    $administrator = adminUser();
    $administrator->update(['name' => 'Current Administrator']);
    $otherAdministrator = adminUser();
    $otherAdministrator->update(['name' => 'Other Administrator']);
    $employee = User::factory()->create(['name' => 'Active Employee']);
    User::factory()->suspended()->create(['name' => 'Inactive Employee']);

    Livewire::actingAs($administrator)
        ->test('compliance.assignments')
        ->assertSee('Current Administrator')
        ->assertSee('Other Administrator')
        ->assertSee('Active Employee')
        ->assertDontSee('Inactive Employee');
});

it('assigns a course to one person from the assignments screen', function (): void {
    $course = publishedManualAssignmentCourse();
    $person = User::factory()->create(['name' => 'Marina Costa']);

    Livewire::actingAs(adminUser())
        ->test('compliance.assignments')
        ->call('startAssigning')
        ->set('assignment.user_id', (string) $person->id)
        ->set('assignment.course_id', (string) $course->id)
        ->set('assignment.due_at', now()->addDays(15)->toDateString())
        ->call('assign')
        ->assertHasNoErrors();

    $assignment = UserTrainingAssignment::query()->firstOrFail();

    expect($assignment->user_id)->toBe($person->id)
        ->and($assignment->origin_type)->toBe(AssignmentOrigin::Manual)
        ->and($assignment->training_requirement_id)->toBeNull()
        ->and($assignment->course_version_id)->toBe($course->current_published_version_id);
});

it('refuses to assign a course with no published version', function (): void {
    $course = Course::factory()->draft()->create();
    $person = User::factory()->create();

    Livewire::actingAs(adminUser())
        ->test('compliance.assignments')
        ->call('startAssigning')
        ->set('assignment.user_id', (string) $person->id)
        ->set('assignment.course_id', (string) $course->id)
        ->call('assign')
        ->assertHasErrors('assignment.course_id');

    expect(UserTrainingAssignment::query()->count())->toBe(0);
});

it('denies assigning to a profile that can only view assignments', function (): void {
    Livewire::actingAs(userWithPermissions([Permission::AssignmentsView]))
        ->test('compliance.assignments')
        ->call('startAssigning')
        ->assertForbidden();
});

it('prevents a manager from assigning training outside their organization tree', function (): void {
    $course = publishedManualAssignmentCourse();
    $department = Department::factory()->create();
    $inside = User::factory()->create(['name' => 'Inside scope']);
    $inside->departments()->attach($department);
    $outside = User::factory()->create(['name' => 'Outside scope']);
    $manager = userWithPermissions([Permission::AssignmentsCreate]);
    $manager->managedDepartments()->attach($department);

    Livewire::actingAs($manager)
        ->test('compliance.assignments')
        ->assertSee('Inside scope')
        ->assertDontSee('Outside scope')
        ->call('startAssigning')
        ->set('assignment.user_id', (string) $outside->id)
        ->set('assignment.course_id', (string) $course->id)
        ->call('assign')
        ->assertForbidden();

    expect(UserTrainingAssignment::query()->count())->toBe(0);
});

it('renders an employee as plain text when the assignment viewer cannot open people', function (): void {
    $department = Department::factory()->create();
    $person = User::factory()->create(['name' => 'Visible Assignment Person']);
    $person->departments()->attach($department);
    $assignment = UserTrainingAssignment::factory()->create(['user_id' => $person]);
    $manager = userWithPermissions([Permission::AssignmentsView]);
    $manager->managedDepartments()->attach($department);
    $personUrl = route('people.show', ['company' => currentCompany(), 'user' => $person]);

    Livewire::actingAs($manager)
        ->test('compliance.assignments')
        ->assertSee($assignment->user->name)
        ->assertDontSee($personUrl, escape: false);
});

it('links an employee when the assignment viewer may also open people', function (): void {
    $department = Department::factory()->create();
    $person = User::factory()->create(['name' => 'Linked Assignment Person']);
    $person->departments()->attach($department);
    UserTrainingAssignment::factory()->create(['user_id' => $person]);
    $manager = userWithPermissions([Permission::AssignmentsView, Permission::PeopleView]);
    $manager->managedDepartments()->attach($department);
    $personUrl = route('people.show', ['company' => currentCompany(), 'user' => $person]);

    Livewire::actingAs($manager)
        ->test('compliance.assignments')
        ->assertSee($personUrl, escape: false);
});
