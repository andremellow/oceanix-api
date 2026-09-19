<?php

namespace App\Actions\Requirements;

use App\Enums\TargetScope;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\TrainingRequirement;
use App\Models\TrainingRequirementTarget;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AddRequirementTargets
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $attributes */
    public function handle(TrainingRequirement $requirement, array $attributes): void
    {
        $validated = Validator::make(['targetForm' => $attributes], [
            'targetForm.scope_type' => ['required', Rule::enum(TargetScope::class)],
            'targetForm.department_id' => ['exclude_unless:targetForm.scope_type,department,department_job_function', 'required', 'integer'],
            'targetForm.job_function_ids' => ['exclude_unless:targetForm.scope_type,job_function,department_job_function', 'required', 'array', 'min:1'],
            'targetForm.job_function_ids.*' => ['integer'],
        ], [
            'targetForm.scope_type.*' => __('Select a valid audience scope.'),
            'targetForm.department_id.*' => __('Select a department.'),
            'targetForm.job_function_ids.required' => __('Select at least one job function.'),
            'targetForm.job_function_ids.min' => __('Select at least one job function.'),
            'targetForm.job_function_ids.array' => __('Select at least one job function.'),
            'targetForm.job_function_ids.*.integer' => __('One or more selected job functions are no longer available.'),
        ])->validate()['targetForm'];

        $scope = TargetScope::from($validated['scope_type']);
        $functionIds = $scope->requiresJobFunction()
            ? array_values(array_unique(array_map(intval(...), $validated['job_function_ids'])))
            : [null];

        DB::transaction(function () use ($requirement, $validated, $scope, $functionIds): void {
            $departmentId = $scope->requiresDepartment() ? (int) $validated['department_id'] : null;
            if ($departmentId !== null && ! Department::query()->active()->whereKey($departmentId)->exists()) {
                throw ValidationException::withMessages([
                    'targetForm.department_id' => __('The selected department is no longer available.'),
                ]);
            }
            if ($scope->requiresJobFunction() && JobFunction::query()->active()->whereKey($functionIds)->count() !== count($functionIds)) {
                throw ValidationException::withMessages([
                    'targetForm.job_function_ids' => __('One or more selected job functions are no longer available.'),
                ]);
            }

            foreach ($functionIds as $functionId) {
                $target = TrainingRequirementTarget::query()->create([
                    'training_requirement_id' => $requirement->id,
                    'scope_type' => $scope,
                    'department_id' => $departmentId,
                    'job_function_id' => $functionId,
                ]);

                $this->audit->log('training_requirement.target_added', $requirement, after: [
                    'scope_type' => $scope->value,
                    'target_id' => $target->id,
                ]);
            }
        });
    }
}
