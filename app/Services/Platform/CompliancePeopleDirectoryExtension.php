<?php

namespace App\Services\Platform;

use App\Models\Department;
use App\Models\JobFunction;
use Illuminate\Database\Eloquent\Builder;
use Oceanix\Platform\Contracts\PeopleDirectoryExtension;

class CompliancePeopleDirectoryExtension implements PeopleDirectoryExtension
{
    public function query(Builder $query, array $filters): void
    {
        $query->with(['departments', 'jobFunctions'])->withCount([
            'assignments as open_assignments_count' => fn ($q) => $q->open(),
            'assignments as overdue_assignments_count' => fn ($q) => $q->overdue(),
        ]);
        if (filled($filters['department_id'] ?? null)) {
            $query->inDepartment((int) $filters['department_id']);
        }
        if (filled($filters['job_function_id'] ?? null)) {
            $query->inJobFunction((int) $filters['job_function_id']);
        }
    }

    public function search(Builder $query, string $term): void
    {
        $query->orWhereRaw("lower(coalesce(employee_id, '')) like ?", [$term]);
    }

    public function data(): array
    {
        return ['departments' => Department::query()->active()->orderBy('name')->get(), 'jobFunctions' => JobFunction::query()->active()->orderBy('name')->get()];
    }
}
