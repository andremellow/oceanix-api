<?php

namespace App\Services\Requirements;

use App\Enums\AssignmentOrigin;
use App\Enums\AssignmentStatus;
use App\Enums\ComplianceEventType;
use App\Models\TrainingRequirement;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns active requirements into individual obligations.
 *
 * Idempotent by construction: an occurrence is keyed on user + requirement + cycle, and the
 * database enforces it, so running the job twice — or twice at once — creates nothing extra.
 * Future occurrences are never generated in advance; only the one that is due now.
 * See docs/product-spec.md §10.
 */
class AssignmentMaterializationService
{
    public function __construct(
        private readonly RequirementEligibilityService $eligibility,
        private readonly RecurrenceService $recurrence,
        private readonly ComplianceEventRecorder $events,
    ) {}

    /**
     * @return array{created: int, skipped: int}
     */
    public function materializeAll(): array
    {
        $created = 0;
        $skipped = 0;

        TrainingRequirement::query()
            ->active()
            ->with(['course', 'targets'])
            ->each(function (TrainingRequirement $requirement) use (&$created, &$skipped): void {
                $result = $this->materialize($requirement);
                $created += $result['created'];
                $skipped += $result['skipped'];
            });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return array{created: int, skipped: int}
     */
    public function materialize(TrainingRequirement $requirement): array
    {
        if (! $requirement->isMaterializable()) {
            return ['created' => 0, 'skipped' => 0];
        }

        $created = 0;
        $skipped = 0;

        $this->eligibility->query($requirement)->each(function (User $user) use ($requirement, &$created, &$skipped): void {
            $this->materializeFor($requirement, $user) === null
                ? $skipped++
                : $created++;
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    public function materializeFor(TrainingRequirement $requirement, User $user): ?UserTrainingAssignment
    {
        $latest = $requirement->assignments()
            ->where('user_id', $user->id)
            ->orderByDesc('cycle_number')
            ->first();

        if ($latest === null) {
            return $this->create($requirement, $user, cycle: 1, dueAt: $this->recurrence->firstDueAt($requirement));
        }

        // An open obligation is the current one; nothing new is due.
        if (! $latest->status->isSatisfied()) {
            return null;
        }

        $dueAt = $this->recurrence->nextDueAt($requirement, $latest);

        if ($dueAt === null) {
            return null;
        }

        $availableAt = $this->recurrence->availableAt($requirement, $dueAt);

        // Materialize the next occurrence only once it is within its lead window, so the
        // whole future series is never generated up front.
        if ($availableAt->isFuture()) {
            return null;
        }

        return $this->create(
            $requirement,
            $user,
            cycle: $latest->cycle_number + 1,
            dueAt: $dueAt,
            availableAt: $availableAt,
            seriesKey: $latest->series_key,
            supersedes: $latest,
        );
    }

    private function create(
        TrainingRequirement $requirement,
        User $user,
        int $cycle,
        Carbon $dueAt,
        ?Carbon $availableAt = null,
        ?string $seriesKey = null,
        ?UserTrainingAssignment $supersedes = null,
    ): ?UserTrainingAssignment {
        try {
            return DB::transaction(function () use ($requirement, $user, $cycle, $dueAt, $availableAt, $seriesKey, $supersedes): UserTrainingAssignment {
                $assignment = UserTrainingAssignment::query()->create([
                    'user_id' => $user->id,
                    'course_id' => $requirement->course_id,
                    // Frozen now: republishing the course later never rewrites this obligation.
                    'course_version_id' => $requirement->course->current_published_version_id,
                    'training_requirement_id' => $requirement->id,
                    'origin_type' => AssignmentOrigin::Requirement,
                    'origin_id' => (string) $requirement->id,
                    'series_key' => $seriesKey ?? (string) Str::uuid(),
                    'cycle_number' => $cycle,
                    'assigned_at' => now(),
                    'available_at' => $availableAt,
                    'due_at' => $dueAt,
                    'status' => AssignmentStatus::Pending,
                    'supersedes_assignment_id' => $supersedes?->id,
                ]);

                $this->events->record(ComplianceEventType::AssignmentCreated, $user->id, [
                    'assignment_id' => $assignment->id,
                    'course_version_id' => $assignment->course_version_id,
                    'metadata' => [
                        'origin' => AssignmentOrigin::Requirement->value,
                        'requirement_id' => $requirement->id,
                        'cycle' => $cycle,
                    ],
                ]);

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            // Another run got there first. That is the guarantee working, not a failure.
            return null;
        }
    }
}
