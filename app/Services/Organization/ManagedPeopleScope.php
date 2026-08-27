<?php

namespace App\Services\Organization;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Resolves direct and indirect reports from managed organizational scopes. */
class ManagedPeopleScope
{
    /** @return list<int> */
    public function userIds(User $manager): array
    {
        if ($manager->isAdmin()) {
            return User::query()->pluck('id')->map(fn (int|string $id): int => (int) $id)->all();
        }

        $resolved = [];
        $frontier = [$manager->id];

        while ($frontier !== []) {
            $managers = User::query()->whereKey($frontier)
                ->with(['managedDepartments:id', 'managedJobFunctions:id'])->get();
            $departmentIds = $managers->flatMap->managedDepartments->pluck('id')->unique()->values();
            $jobFunctionIds = $managers->flatMap->managedJobFunctions->pluck('id')->unique()->values();

            if ($departmentIds->isEmpty() && $jobFunctionIds->isEmpty()) {
                break;
            }

            $direct = User::query()->whereKeyNot($manager->id)
                ->where(function (Builder $query) use ($departmentIds, $jobFunctionIds): void {
                    $query->when($departmentIds->isNotEmpty(), fn (Builder $scoped) => $scoped
                        ->whereHas('departments', fn (Builder $departments) => $departments
                            ->whereKey($departmentIds)
                            ->where(fn (Builder $dates) => $dates->whereNull('user_department.starts_at')
                                ->orWhere('user_department.starts_at', '<=', now()->toDateString()))
                            ->where(fn (Builder $dates) => $dates->whereNull('user_department.ends_at')
                                ->orWhere('user_department.ends_at', '>=', now()->toDateString()))));
                    $query->when($jobFunctionIds->isNotEmpty(), fn (Builder $scoped) => $scoped
                        ->orWhereHas('jobFunctions', fn (Builder $functions) => $functions
                            ->whereKey($jobFunctionIds)
                            ->where(fn (Builder $dates) => $dates->whereNull('user_job_function.starts_at')
                                ->orWhere('user_job_function.starts_at', '<=', now()->toDateString()))
                            ->where(fn (Builder $dates) => $dates->whereNull('user_job_function.ends_at')
                                ->orWhere('user_job_function.ends_at', '>=', now()->toDateString()))));
                })->pluck('id')->all();

            $frontier = array_values(array_filter($direct, function (int $id) use (&$resolved): bool {
                if (isset($resolved[$id])) {
                    return false;
                }
                $resolved[$id] = true;

                return true;
            }));
        }

        return array_map('intval', array_keys($resolved));
    }

    public function canView(User $manager, User|int $person): bool
    {
        $personId = $person instanceof User ? $person->id : $person;

        return $manager->id === $personId || $manager->isAdmin() || in_array($personId, $this->userIds($manager), true);
    }
}
