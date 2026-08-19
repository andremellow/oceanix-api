<?php

use App\Enums\TargetScope;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\TrainingRequirement;
use App\Models\TrainingRequirementTarget;
use App\Models\User;
use App\Services\Requirements\RequirementEligibilityService;

it('resolves nobody when a requirement has no target', function (): void {
    User::factory()->count(3)->create();
    $requirement = TrainingRequirement::factory()->create();

    expect(app(RequirementEligibilityService::class)->count($requirement))->toBe(0);
});

it('resolves the whole active workforce for an everyone target', function (): void {
    User::factory()->count(2)->create();
    User::factory()->terminated()->create();

    $requirement = TrainingRequirement::factory()->create();
    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement->id,
        'scope_type' => TargetScope::Everyone,
    ]);

    expect(app(RequirementEligibilityService::class)->count($requirement))->toBe(2);
});

it('intersects department and job function for a combined target', function (): void {
    $operations = Department::factory()->create(['code' => 'OPS']);
    $marine = Department::factory()->create(['code' => 'MAR']);
    $supervisor = JobFunction::factory()->create(['code' => 'SUP']);
    $welder = JobFunction::factory()->create(['code' => 'WLD']);

    $match = User::factory()->create(['name' => 'Marina Costa']);
    $match->departments()->attach($operations);
    $match->jobFunctions()->attach($supervisor);

    $wrongFunction = User::factory()->create(['name' => 'Rafael Duarte']);
    $wrongFunction->departments()->attach($operations);
    $wrongFunction->jobFunctions()->attach($welder);

    $wrongDepartment = User::factory()->create(['name' => 'Helena Vasques']);
    $wrongDepartment->departments()->attach($marine);
    $wrongDepartment->jobFunctions()->attach($supervisor);

    $requirement = TrainingRequirement::factory()->create();
    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement->id,
        'scope_type' => TargetScope::DepartmentJobFunction,
        'department_id' => $operations->id,
        'job_function_id' => $supervisor->id,
    ]);

    $resolved = app(RequirementEligibilityService::class)->resolve($requirement);

    expect($resolved->pluck('name')->all())->toBe(['Marina Costa']);
});

it('excludes a person whose organizational link already ended', function (): void {
    $department = Department::factory()->create();
    $user = User::factory()->create();
    $user->departments()->attach($department, ['ends_at' => now()->subDay()->toDateString()]);

    $requirement = TrainingRequirement::factory()->create();
    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement->id,
        'scope_type' => TargetScope::Department,
        'department_id' => $department->id,
    ]);

    expect(app(RequirementEligibilityService::class)->count($requirement))->toBe(0);
});

it('unions several targets in one requirement', function (): void {
    $operations = Department::factory()->create();
    $welder = JobFunction::factory()->create();

    $byDepartment = User::factory()->create();
    $byDepartment->departments()->attach($operations);

    $byFunction = User::factory()->create();
    $byFunction->jobFunctions()->attach($welder);

    User::factory()->create();

    $requirement = TrainingRequirement::factory()->create();
    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement->id,
        'scope_type' => TargetScope::Department,
        'department_id' => $operations->id,
    ]);
    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement->id,
        'scope_type' => TargetScope::JobFunction,
        'job_function_id' => $welder->id,
    ]);

    expect(app(RequirementEligibilityService::class)->count($requirement))->toBe(2);
});
