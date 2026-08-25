<?php

namespace App\Actions\People;

use App\Models\Department;
use App\Models\JobFunction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Validation\ValidationException;

class AssignManagementScopes
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param list<int> $departmentIds @param list<int> $jobFunctionIds */
    public function handle(User $user, array $departmentIds, array $jobFunctionIds): User
    {
        $departmentIds = collect($departmentIds)->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $jobFunctionIds = collect($jobFunctionIds)->map(fn ($id): int => (int) $id)->unique()->values()->all();

        if (Department::query()->whereKey($departmentIds)->count() !== count($departmentIds)
            || JobFunction::query()->whereKey($jobFunctionIds)->count() !== count($jobFunctionIds)) {
            throw ValidationException::withMessages(['management' => __('A selected management scope does not belong to this company.')]);
        }

        $before = [
            'department_ids' => $user->managedDepartments()->pluck('departments.id')->all(),
            'job_function_ids' => $user->managedJobFunctions()->pluck('job_functions.id')->all(),
        ];
        $user->managedDepartments()->sync($departmentIds);
        $user->managedJobFunctions()->sync($jobFunctionIds);
        $this->audit->log('person.management_scopes_updated', $user, before: $before, after: [
            'department_ids' => $departmentIds,
            'job_function_ids' => $jobFunctionIds,
        ]);

        return $user->load(['managedDepartments', 'managedJobFunctions']);
    }
}
