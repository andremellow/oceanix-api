<?php

namespace App\Services\Compliance;

use App\Enums\AssignmentStatus;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read projection behind the administrative dashboard.
 *
 * Compliance is derived from materialized assignments and their dates — never from the
 * user's current department or job function, which only describe who would be targeted
 * today. See docs/product-spec.md §15.
 */
class ComplianceOverview
{
    /** An assignment due within this window is surfaced as "due soon". */
    public const DUE_SOON_DAYS = 14;

    /** Overdue beyond this many days is escalated as critical. */
    public const CRITICAL_OVERDUE_DAYS = 30;

    /**
     * @return array{
     *     people: int, compliant: int, due_soon: int, overdue: int,
     *     critical_overdue: int, completion_rate: int, in_progress: int
     * }
     */
    public function metrics(): array
    {
        $people = User::query()->where('status', UserStatus::Active->value)->count();

        $overdueQuery = UserTrainingAssignment::query()->overdue();
        $overdue = (clone $overdueQuery)->count();

        $criticalOverdue = (clone $overdueQuery)
            ->where('due_at', '<', now()->subDays(self::CRITICAL_OVERDUE_DAYS))
            ->count();

        $peopleWithOverdue = (clone $overdueQuery)->distinct()->count('user_id');

        $completed = UserTrainingAssignment::query()
            ->where('status', AssignmentStatus::Completed->value)
            ->count();

        $closed = $completed + $overdue;

        return [
            'people' => $people,
            'compliant' => max(0, $people - $peopleWithOverdue),
            'due_soon' => UserTrainingAssignment::query()->dueWithin(self::DUE_SOON_DAYS)->count(),
            'overdue' => $overdue,
            'critical_overdue' => $criticalOverdue,
            'in_progress' => UserTrainingAssignment::query()
                ->where('status', AssignmentStatus::InProgress->value)
                ->count(),
            'completion_rate' => $closed > 0 ? (int) round($completed / $closed * 100) : 100,
        ];
    }

    /**
     * Operational table behind the dashboard and the assignments screen.
     *
     * @param  array{
     *     search?: string|null, department_id?: int|null, job_function_id?: int|null,
     *     course_id?: int|null, status?: string|null, due_bucket?: string|null, origin?: string|null
     * }  $filters
     * @return Builder<UserTrainingAssignment>
     */
    public function assignments(array $filters = []): Builder
    {
        $query = UserTrainingAssignment::query()
            ->with(['user.departments', 'user.jobFunctions', 'course', 'courseVersion']);

        if (! empty($filters['search'])) {
            $search = '%'.strtolower((string) $filters['search']).'%';
            $query->where(function (Builder $scoped) use ($search): void {
                $scoped->whereHas('user', fn (Builder $user) => $user
                    ->whereRaw('lower(name) like ?', [$search])
                    ->orWhereRaw('lower(email) like ?', [$search]))
                    ->orWhereHas('course', fn (Builder $course) => $course
                        ->whereRaw('lower(title) like ?', [$search])
                        ->orWhereRaw('lower(code) like ?', [$search]));
            });
        }

        if (! empty($filters['department_id'])) {
            $query->whereHas('user', fn (Builder $user) => $user->inDepartment((int) $filters['department_id']));
        }

        if (! empty($filters['job_function_id'])) {
            $query->whereHas('user', fn (Builder $user) => $user->inJobFunction((int) $filters['job_function_id']));
        }

        if (! empty($filters['course_id'])) {
            $query->where('course_id', (int) $filters['course_id']);
        }

        if (! empty($filters['origin'])) {
            $query->where('origin_type', $filters['origin']);
        }

        if (! empty($filters['status'])) {
            $filters['status'] === 'open'
                ? $query->open()
                : $query->where('status', $filters['status']);
        }

        $this->applyDueBucket($query, $filters['due_bucket'] ?? null);

        return $query->orderByRaw('due_at is null')->orderBy('due_at');
    }

    /**
     * Overdue banding used by the operational filters: due soon, then 1–7, 8–30, 31–60 and
     * 60+ days late.
     *
     * @param  Builder<UserTrainingAssignment>  $query
     */
    private function applyDueBucket(Builder $query, ?string $bucket): void
    {
        match ($bucket) {
            'due_soon' => $query->dueWithin(self::DUE_SOON_DAYS),
            'overdue_1_7' => $query->overdue()
                ->where('due_at', '>=', now()->subDays(7)),
            'overdue_8_30' => $query->overdue()
                ->whereBetween('due_at', [now()->subDays(30), now()->subDays(8)]),
            'overdue_31_60' => $query->overdue()
                ->whereBetween('due_at', [now()->subDays(60), now()->subDays(31)]),
            'overdue_60_plus' => $query->overdue()
                ->where('due_at', '<', now()->subDays(60)),
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public function dueBuckets(): array
    {
        return [
            'due_soon' => __('Due in the next :days days', ['days' => self::DUE_SOON_DAYS]),
            'overdue_1_7' => __('Overdue 1–7 days'),
            'overdue_8_30' => __('Overdue 8–30 days'),
            'overdue_31_60' => __('Overdue 31–60 days'),
            'overdue_60_plus' => __('Overdue 60+ days'),
        ];
    }
}
