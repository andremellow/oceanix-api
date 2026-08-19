<?php

use App\Enums\AssignmentOrigin;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use Livewire\Livewire;

it('assigns a course to one person from the assignments screen', function (): void {
    $course = assignableCourseForRequirements();
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
