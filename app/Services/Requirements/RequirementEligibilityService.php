<?php

namespace App\Services\Requirements;

use App\Enums\TargetScope;
use App\Models\TrainingRequirement;
use App\Models\TrainingRequirementTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves which people a requirement currently applies to.
 *
 * This answers "who is in scope today", which is a different question from "who already
 * owes this training" — that is answered by the materialized assignments. Organizational
 * links are matched with their effective dates so history stays reconstructable.
 * See docs/product-spec.md §8.
 */
class RequirementEligibilityService
{
    /**
     * @return Collection<int, User>
     */
    public function resolve(TrainingRequirement $requirement): Collection
    {
        return $this->query($requirement)->orderBy('name')->get();
    }

    public function count(TrainingRequirement $requirement): int
    {
        return $this->query($requirement)->count();
    }

    /**
     * @return Builder<User>
     */
    public function query(TrainingRequirement $requirement): Builder
    {
        $targets = $requirement->relationLoaded('targets')
            ? $requirement->targets
            : $requirement->targets()->get();

        $query = User::query()->eligibleForTraining();

        if ($targets->isEmpty()) {
            // No target means nobody, never everybody: an unfinished rule must not
            // silently assign the whole company.
            return $query->whereRaw('1 = 0');
        }

        if ($targets->contains(fn (TrainingRequirementTarget $target): bool => $target->scope_type === TargetScope::Everyone)) {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($targets): void {
            foreach ($targets as $target) {
                $outer->orWhere(function (Builder $scoped) use ($target): void {
                    if ($target->scope_type->requiresDepartment() && $target->department_id !== null) {
                        $scoped->inDepartment($target->department_id);
                    }

                    if ($target->scope_type->requiresJobFunction() && $target->job_function_id !== null) {
                        $scoped->inJobFunction($target->job_function_id);
                    }
                });
            }
        });
    }
}
