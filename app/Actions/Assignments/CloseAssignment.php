<?php

namespace App\Actions\Assignments;

use App\Enums\AssignmentStatus;
use App\Enums\ComplianceEventType;
use App\Models\UserTrainingAssignment;
use App\Services\Audit\AuditLogger;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cancels or waives an obligation.
 *
 * Neither deletes anything: the assignment keeps its history and its evidence, and the
 * reason is recorded, because "this person did not have to do it" is a claim that has to be
 * defensible later. A waiver satisfies compliance; a cancellation simply ends it.
 */
class CloseAssignment
{
    public function __construct(
        private readonly ComplianceEventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(UserTrainingAssignment $assignment, AssignmentStatus $status, string $reason): UserTrainingAssignment
    {
        if (! in_array($status, [AssignmentStatus::Cancelled, AssignmentStatus::Waived], true)) {
            throw new InvalidArgumentException('An assignment can only be closed as cancelled or waived.');
        }

        return DB::transaction(function () use ($assignment, $status, $reason): UserTrainingAssignment {
            $before = $assignment->status;

            $assignment->update([
                'status' => $status,
                'metadata' => [
                    ...($assignment->metadata ?? []),
                    'closed_reason' => $reason,
                    'closed_by' => auth()->id(),
                    'closed_at' => now()->toIso8601String(),
                ],
            ]);

            $this->events->record(
                $status === AssignmentStatus::Waived
                    ? ComplianceEventType::AssignmentWaived
                    : ComplianceEventType::AssignmentCancelled,
                $assignment->user_id,
                [
                    'assignment_id' => $assignment->id,
                    'course_version_id' => $assignment->course_version_id,
                    'metadata' => ['reason' => $reason],
                ],
            );

            $this->audit->log(
                $status === AssignmentStatus::Waived ? 'assignment.waived' : 'assignment.cancelled',
                $assignment,
                before: ['status' => $before->value],
                after: ['status' => $status->value, 'reason' => $reason],
            );

            return $assignment->refresh();
        });
    }
}
