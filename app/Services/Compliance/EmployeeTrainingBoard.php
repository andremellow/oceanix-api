<?php

namespace App\Services\Compliance;

use App\Enums\AssignmentStatus;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use Illuminate\Database\Eloquent\Collection;

/**
 * The employee's own view: action first, catalog never.
 *
 * Overdue, then due soon, then in progress, then everything already satisfied.
 * See docs/product-spec.md §15.
 */
class EmployeeTrainingBoard
{
    /**
     * @return array{
     *     overdue: Collection<int, UserTrainingAssignment>,
     *     due_soon: Collection<int, UserTrainingAssignment>,
     *     in_progress: Collection<int, UserTrainingAssignment>,
     *     upcoming: Collection<int, UserTrainingAssignment>,
     *     completed: Collection<int, UserTrainingAssignment>
     * }
     */
    public function build(User $user): array
    {
        $assignments = $user->assignments()
            ->with(['course', 'courseVersion', 'certificate'])
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->get();

        $open = $assignments->filter(fn (UserTrainingAssignment $a): bool => $a->status->isOpen());

        $overdue = $open->filter(fn (UserTrainingAssignment $a): bool => $a->isOverdue());
        $dueSoon = $open->filter(fn (UserTrainingAssignment $a): bool => ! $a->isOverdue()
            && $a->due_at !== null
            && $a->due_at->lte(now()->addDays(ComplianceOverview::DUE_SOON_DAYS)));
        $inProgress = $open->filter(fn (UserTrainingAssignment $a): bool => $a->status === AssignmentStatus::InProgress
            && ! $overdue->contains($a)
            && ! $dueSoon->contains($a));

        return [
            'overdue' => $overdue->values(),
            'due_soon' => $dueSoon->values(),
            'in_progress' => $inProgress->values(),
            'upcoming' => $open
                ->reject(fn (UserTrainingAssignment $a): bool => $overdue->contains($a)
                    || $dueSoon->contains($a)
                    || $inProgress->contains($a))
                ->values(),
            'completed' => $assignments
                ->filter(fn (UserTrainingAssignment $a): bool => $a->status->isSatisfied())
                ->sortByDesc('completed_at')
                ->values(),
        ];
    }
}
