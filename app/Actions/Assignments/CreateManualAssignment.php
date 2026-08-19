<?php

namespace App\Actions\Assignments;

use App\Enums\AssignmentOrigin;
use App\Enums\AssignmentStatus;
use App\Enums\ComplianceEventType;
use App\Models\Course;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Audit\AuditLogger;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates an assignment directly, without a training requirement.
 *
 * An obligation can exist on its own — an ad-hoc onboarding, a corrective action, later a
 * mobilization import. The assigned course version is frozen at creation.
 * See docs/product-spec.md §9.
 */
class CreateManualAssignment
{
    public function __construct(
        private readonly ComplianceEventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(
        User $user,
        Course $course,
        ?Carbon $dueAt = null,
        ?Carbon $availableAt = null,
        AssignmentOrigin $origin = AssignmentOrigin::Manual,
        ?string $originId = null,
    ): UserTrainingAssignment {
        $versionId = $course->current_published_version_id;

        if ($versionId === null) {
            throw new RuntimeException('The course has no published version to assign.');
        }

        return DB::transaction(function () use ($user, $course, $versionId, $dueAt, $availableAt, $origin, $originId): UserTrainingAssignment {
            $assignment = UserTrainingAssignment::query()->create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'course_version_id' => $versionId,
                'training_requirement_id' => null,
                'origin_type' => $origin,
                'origin_id' => $originId,
                'assigned_at' => now(),
                'available_at' => $availableAt,
                'due_at' => $dueAt,
                'status' => AssignmentStatus::Pending,
            ]);

            $this->events->record(ComplianceEventType::AssignmentCreated, $user->id, [
                'assignment_id' => $assignment->id,
                'course_version_id' => $versionId,
                'metadata' => [
                    'origin' => $origin->value,
                    'due_at' => $dueAt?->toIso8601String(),
                ],
            ]);

            $this->audit->log('assignment.created', $assignment, after: [
                'user_id' => $user->id,
                'course_id' => $course->id,
                'course_version_id' => $versionId,
                'due_at' => $dueAt?->toDateString(),
            ]);

            return $assignment;
        });
    }
}
