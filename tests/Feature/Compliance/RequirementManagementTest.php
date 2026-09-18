<?php

use App\Enums\Permission;
use App\Enums\RequirementStatus;
use App\Enums\TargetScope;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\TrainingRequirement;
use App\Models\TrainingRequirementTarget;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use Livewire\Livewire;

function assignableCourseForRequirements(): Course
{
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);

    return $course->refresh();
}

it('creates a requirement as a draft', function (): void {
    $course = assignableCourseForRequirements();

    Livewire::actingAs(adminUser())
        ->test('compliance.requirements')
        ->call('startCreating')
        ->set('form.name', 'Offshore safety — Operations supervisors')
        ->set('form.course_id', (string) $course->id)
        ->set('form.frequency_type', 'months')
        ->set('form.frequency_value', '6')
        ->call('save')
        ->assertHasNoErrors();

    $requirement = TrainingRequirement::query()->firstOrFail();

    expect($requirement->status)->toBe(RequirementStatus::Draft)
        ->and($requirement->frequency_value)->toBe(6);
});

it('clears the interval when the requirement is one-off', function (): void {
    $course = assignableCourseForRequirements();

    Livewire::actingAs(adminUser())
        ->test('compliance.requirements')
        ->call('startCreating')
        ->set('form.name', 'Induction')
        ->set('form.course_id', (string) $course->id)
        ->set('form.frequency_type', 'once')
        ->set('form.frequency_value', '12')
        ->call('save');

    expect(TrainingRequirement::query()->firstOrFail()->frequency_value)->toBeNull();
});

it('shows friendly localized validation messages instead of internal field names', function (): void {
    app()->setLocale('pt_BR');

    Livewire::actingAs(adminUser())
        ->test('compliance.requirements')
        ->call('startCreating')
        ->call('save')
        ->assertHasErrors(['form.name', 'form.course_id'])
        ->assertSee('Informe o nome da regra.')
        ->assertSee('Selecione um curso.')
        ->assertDontSee('The form.name field is required.')
        ->assertDontSee('The form.course id field is required.');
});

it('opens a three month schedule preview without materializing assignments', function (): void {
    $requirement = TrainingRequirement::factory()->create([
        'course_id' => assignableCourseForRequirements(),
        'name' => 'Schedule preview requirement',
        'frequency_value' => 1,
        'due_days_after_assignment' => 10,
    ]);
    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement,
        'scope_type' => TargetScope::Everyone,
    ]);
    User::factory()->create(['name' => 'Preview Person']);

    Livewire::actingAs(adminUser())
        ->test('compliance.requirements')
        ->call('showSchedule', $requirement->id)
        ->assertSee('Preview Person')
        ->assertSee(__('Expected'));

    expect(UserTrainingAssignment::query()->count())->toBe(0);
});

it('refuses to activate a requirement with no audience', function (): void {
    $requirement = TrainingRequirement::factory()->draft()->create([
        'course_id' => assignableCourseForRequirements()->id,
    ]);

    Livewire::actingAs(adminUser())
        ->test('compliance.requirements')
        ->call('changeStatus', $requirement->id, 'active')
        ->assertHasErrors('activation');

    expect($requirement->fresh()->status)->toBe(RequirementStatus::Draft);
});

it('refuses to activate a requirement whose course has nothing published', function (): void {
    $requirement = TrainingRequirement::factory()->draft()->create([
        'course_id' => Course::factory()->draft()->create()->id,
    ]);
    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement->id,
        'scope_type' => TargetScope::Everyone,
    ]);

    Livewire::actingAs(adminUser())
        ->test('compliance.requirements')
        ->call('changeStatus', $requirement->id, 'active')
        ->assertHasErrors('activation');

    expect($requirement->fresh()->status)->toBe(RequirementStatus::Draft);
});

it('activates a requirement that has an audience and a published course', function (): void {
    $requirement = TrainingRequirement::factory()->draft()->create([
        'course_id' => assignableCourseForRequirements()->id,
    ]);
    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement->id,
        'scope_type' => TargetScope::Everyone,
    ]);

    Livewire::actingAs(adminUser())
        ->test('compliance.requirements')
        ->call('changeStatus', $requirement->id, 'active')
        ->assertHasNoErrors();

    expect($requirement->fresh()->status)->toBe(RequirementStatus::Active);
});

it('adds a department target and keeps the audience explicit', function (): void {
    $requirement = TrainingRequirement::factory()->draft()->create([
        'course_id' => assignableCourseForRequirements()->id,
    ]);
    $department = Department::factory()->create();

    Livewire::actingAs(adminUser())
        ->test('compliance.requirements')
        ->call('startTargeting', $requirement->id)
        ->set('targetForm.scope_type', TargetScope::Department->value)
        ->set('targetForm.department_id', (string) $department->id)
        ->call('addTarget')
        ->assertHasNoErrors();

    expect($requirement->targets()->count())->toBe(1)
        ->and($requirement->targets()->first()->department_id)->toBe($department->id);
});

it('requires a department when the scope needs one', function (): void {
    $requirement = TrainingRequirement::factory()->draft()->create([
        'course_id' => assignableCourseForRequirements()->id,
    ]);

    Livewire::actingAs(adminUser())
        ->test('compliance.requirements')
        ->call('startTargeting', $requirement->id)
        ->set('targetForm.scope_type', TargetScope::Department->value)
        ->set('targetForm.department_id', '')
        ->call('addTarget')
        ->assertHasErrors('targetForm.department_id');

    expect($requirement->targets()->count())->toBe(0);
});

it('lets a viewer read requirements but not change them', function (): void {
    $requirement = TrainingRequirement::factory()->draft()->create([
        'course_id' => assignableCourseForRequirements()->id,
    ]);

    Livewire::actingAs(userWithPermissions([Permission::RequirementsView]))
        ->test('compliance.requirements')
        ->call('startEditing', $requirement->id)
        ->assertForbidden();
});

it('lets an editor edit but not activate', function (): void {
    $requirement = TrainingRequirement::factory()->draft()->create([
        'course_id' => assignableCourseForRequirements()->id,
    ]);
    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement->id,
        'scope_type' => TargetScope::Everyone,
    ]);

    Livewire::actingAs(userWithPermissions([Permission::RequirementsUpdate]))
        ->test('compliance.requirements')
        ->call('changeStatus', $requirement->id, 'active')
        ->assertForbidden();
});

it('validates and saves each audience scope without changing other obligations', function (TargetScope $scope): void {
    $unselected = TrainingRequirement::factory()->draft()->create(['id' => 21]);
    $requirement = TrainingRequirement::factory()->draft()->create(['id' => 42]);
    $existing = TrainingRequirementTarget::factory()->create(['training_requirement_id' => $requirement->id, 'scope_type' => TargetScope::Everyone]);
    $assignment = UserTrainingAssignment::factory()->create(['training_requirement_id' => $requirement->id]);
    $existing->refresh();
    $assignmentBefore = $assignment->refresh()->getAttributes();
    $department = Department::factory()->create();
    $job = JobFunction::factory()->create();
    $admin = adminUser();

    $component = Livewire::actingAs($admin)
        ->test('compliance.requirements')
        ->call('startTargeting', $requirement->id)
        ->assertSet('targeting', true)
        ->assertSet('targetingId', $requirement->id)
        ->set('targetForm.scope_type', $scope->value);

    $required = array_filter([
        $scope->requiresDepartment() ? 'targetForm.department_id' : null,
        $scope->requiresJobFunction() ? 'targetForm.job_function_id' : null,
    ]);
    if ($required !== []) {
        $component->call('addTarget')->assertHasErrors(array_values($required))
            ->assertSet('targeting', true)->assertSet('targetingId', $requirement->id);
        expect($requirement->targets()->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'training_requirement.target_added')->count())->toBe(0);
    }

    $component->set('targetForm.department_id', (string) $department->id)
        ->set('targetForm.job_function_id', (string) $job->id)
        ->call('addTarget')->assertHasNoErrors()
        ->assertSet('targeting', false)->assertSet('targetingId', null);

    $target = $requirement->targets()->whereKeyNot($existing->id)->sole();
    $audit = AuditLog::query()->where('action', 'training_requirement.target_added')->sole();
    expect($target->scope_type)->toBe($scope)
        ->and($target->department_id)->toBe($scope->requiresDepartment() ? $department->id : null)
        ->and($target->job_function_id)->toBe($scope->requiresJobFunction() ? $job->id : null)
        ->and($unselected->targets()->count())->toBe(0)
        ->and($existing->fresh()->getAttributes())->toBe($existing->getAttributes())
        ->and($assignment->fresh()->getAttributes())->toBe($assignmentBefore)
        ->and($requirement->fresh()->status)->toBe(RequirementStatus::Draft)
        ->and($audit->auditable_id)->toBe($requirement->id)
        ->and($audit->actor_id)->toBe($admin->id)
        ->and($audit->after)->toBe(['scope_type' => $scope->value, 'target_id' => $target->id]);
})->with(TargetScope::cases());

it('denies audience changes without update permission', function (): void {
    $requirement = TrainingRequirement::factory()->draft()->create();
    $viewer = userWithPermissions([Permission::RequirementsView]);

    Livewire::actingAs($viewer)->test('compliance.requirements')
        ->call('startTargeting', $requirement->id)->assertForbidden();
    Livewire::actingAs($viewer)->test('compliance.requirements')
        ->set('targetingId', $requirement->id)
        ->set('targetForm.scope_type', 'everyone')
        ->call('addTarget')->assertForbidden();

    expect($requirement->targets()->count())->toBe(0);
});
