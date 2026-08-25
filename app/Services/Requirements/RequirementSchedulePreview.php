<?php

namespace App\Services\Requirements;

use App\Enums\RenewalBasis;
use App\Enums\RequirementStatus;
use App\Models\TrainingRequirement;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Builds a read-only recurrence preview without materializing future assignments. */
class RequirementSchedulePreview
{
    public const DEFAULT_MONTHS = 3;

    public function __construct(
        private readonly RequirementEligibilityService $eligibility,
        private readonly RecurrenceService $recurrence,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function forRequirement(TrainingRequirement $requirement, int $months = self::DEFAULT_MONTHS): Collection
    {
        $people = $this->eligibility->resolve($requirement)
            ->concat(User::query()->whereIn(
                'id',
                $requirement->assignments()->select('user_id'),
            )->get())
            ->unique('id');

        return $people
            ->flatMap(fn (User $user): Collection => $this->forPair($requirement, $user, $months))
            ->sortBy(['due_at', 'person_name'])
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function forUser(User $user, int $months = self::DEFAULT_MONTHS): Collection
    {
        return TrainingRequirement::query()
            ->where(function ($query) use ($user): void {
                $query->where('status', RequirementStatus::Active->value)
                    ->orWhereHas('assignments', fn ($assignments) => $assignments->where('user_id', $user->id));
            })
            ->with(['course', 'targets'])
            ->get()
            ->filter(fn (TrainingRequirement $requirement): bool => $requirement->assignments()
                ->where('user_id', $user->id)->exists()
                || $this->eligibility->query($requirement)->whereKey($user->id)->exists())
            ->flatMap(fn (TrainingRequirement $requirement): Collection => $this->forPair($requirement, $user, $months))
            ->sortBy(['due_at', 'requirement_name'])
            ->values();
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function paginateForRequirement(
        TrainingRequirement $requirement,
        int $page,
        int $perPage = 25,
        int $months = self::DEFAULT_MONTHS,
    ): LengthAwarePaginator {
        return $this->paginate($this->forRequirement($requirement, $months), $page, $perPage, 'requirementSchedulePage');
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function paginateForUser(
        User $user,
        int $page,
        int $perPage = 25,
        int $months = self::DEFAULT_MONTHS,
    ): LengthAwarePaginator {
        return $this->paginate($this->forUser($user, $months), $page, $perPage, 'personSchedulePage');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function forPair(TrainingRequirement $requirement, User $user, int $months): Collection
    {
        $from = now()->startOfDay();
        $until = now()->addMonths($months)->endOfDay();
        $assignments = $requirement->assignments()
            ->where('user_id', $user->id)
            ->orderBy('cycle_number')
            ->get();
        $rows = collect();

        foreach ($assignments as $assignment) {
            if ($assignment->due_at !== null
                && $assignment->due_at->lte($until)
                && ($assignment->due_at->gte($from) || $assignment->status->isOpen())) {
                $rows->push($this->row(
                    $requirement,
                    $user,
                    $assignment->cycle_number,
                    $assignment->due_at,
                    $assignment->available_at ?? $assignment->assigned_at,
                    true,
                    false,
                    $assignment->status->label(),
                ));
            }
        }

        if (! $requirement->status->materializes()) {
            return $rows;
        }

        $latest = $assignments->last();
        $cycle = ($latest?->cycle_number ?? 0) + 1;
        $dueAt = $latest === null
            ? $this->recurrence->firstDueAt($requirement, $this->firstCycleAnchor($requirement))
            : $this->recurrence->nextDueAt($requirement, $latest);

        while ($dueAt !== null && $dueAt->lte($until)) {
            if ($requirement->effective_until !== null && $dueAt->gt($requirement->effective_until->endOfDay())) {
                break;
            }

            if ($dueAt->gte($from)) {
                $rows->push($this->row(
                    $requirement,
                    $user,
                    $cycle,
                    $dueAt,
                    $this->recurrence->availableAt($requirement, $dueAt),
                    false,
                    $requirement->renewal_basis === RenewalBasis::FromCompletion,
                    __('Expected'),
                ));
            }

            if (! $requirement->frequency_type->isRecurring()) {
                break;
            }

            $dueAt = $requirement->frequency_type->advance($dueAt, $requirement->frequency_value);
            $cycle++;
        }

        return $rows;
    }

    private function firstCycleAnchor(TrainingRequirement $requirement): Carbon
    {
        return $requirement->effective_from !== null && $requirement->effective_from->isFuture()
            ? $requirement->effective_from->copy()
            : now();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginate(Collection $rows, int $page, int $perPage, string $pageName): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => $pageName],
        );
    }

    /** @return array<string, mixed> */
    private function row(
        TrainingRequirement $requirement,
        User $user,
        int $cycle,
        Carbon $dueAt,
        ?Carbon $availableAt,
        bool $materialized,
        bool $estimated,
        string $status,
    ): array {
        return [
            'requirement_id' => $requirement->id,
            'requirement_name' => $requirement->name,
            'course_title' => $requirement->course->title,
            'person_id' => $user->id,
            'person_name' => $user->name,
            'cycle' => $cycle,
            'available_at' => $availableAt,
            'due_at' => $dueAt,
            'materialized' => $materialized,
            'estimated' => $estimated,
            'status' => $status,
        ];
    }
}
