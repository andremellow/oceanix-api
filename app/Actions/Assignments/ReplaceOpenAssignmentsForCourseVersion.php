<?php

namespace App\Actions\Assignments;

use App\Enums\AssignmentStatus;
use App\Enums\ComplianceEventType;
use App\Models\CourseVersion;
use App\Models\UserTrainingAssignment;
use App\Services\Audit\AuditLogger;
use App\Services\Compliance\ComplianceEventRecorder;

/** Replaces open obligations without ever rewriting their frozen version or evidence. */
class ReplaceOpenAssignmentsForCourseVersion
{
    public function __construct(
        private readonly ComplianceEventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(CourseVersion $version): int
    {
        $assignments = UserTrainingAssignment::query()
            ->where('course_id', $version->course_id)
            ->where('course_version_id', '!=', $version->id)
            ->open()
            ->lockForUpdate()
            ->get();

        foreach ($assignments as $assignment) {
            $reason = "Replaced by course version {$version->version_number}.";

            $assignment->update([
                'status' => AssignmentStatus::Cancelled,
                'metadata' => [
                    ...($assignment->metadata ?? []),
                    'closed_reason' => $reason,
                    'closed_by' => auth()->id(),
                    'closed_at' => now()->toIso8601String(),
                    'replaced_by_course_version_id' => $version->id,
                ],
            ]);

            $replacement = UserTrainingAssignment::query()->create([
                'user_id' => $assignment->user_id,
                'course_id' => $assignment->course_id,
                'course_version_id' => $version->id,
                'training_requirement_id' => $assignment->training_requirement_id,
                'origin_type' => $assignment->origin_type,
                'origin_id' => $assignment->origin_id,
                'series_key' => $assignment->series_key,
                'cycle_number' => $assignment->cycle_number + 1,
                'assigned_at' => now(),
                'available_at' => $assignment->available_at,
                'due_at' => $assignment->due_at,
                'expires_at' => $assignment->expires_at,
                'status' => AssignmentStatus::Pending,
                'supersedes_assignment_id' => $assignment->id,
                'metadata' => ['replaced_course_version_id' => $assignment->course_version_id],
            ]);

            $this->events->record(ComplianceEventType::AssignmentCancelled, $assignment->user_id, [
                'assignment_id' => $assignment->id,
                'course_version_id' => $assignment->course_version_id,
                'metadata' => ['reason' => $reason],
            ]);
            $this->events->record(ComplianceEventType::AssignmentCreated, $replacement->user_id, [
                'assignment_id' => $replacement->id,
                'course_version_id' => $version->id,
                'metadata' => ['origin' => $replacement->origin_type->value, 'supersedes_assignment_id' => $assignment->id],
            ]);

            $this->audit->log('assignment.replaced_for_course_version', $replacement, after: [
                'previous_assignment_id' => $assignment->id,
                'course_version_id' => $version->id,
            ]);
        }

        return $assignments->count();
    }
}
