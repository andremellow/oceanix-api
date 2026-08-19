<?php

namespace App\Actions\Training;

use App\Enums\AssignmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\ComplianceEventType;
use App\Models\CourseAttempt;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Opens (or resumes) the course attempt behind an assignment.
 *
 * A new run never rewrites the previous one: if the last attempt finished, this starts a
 * new numbered attempt and the old rows stay as evidence. See docs/product-spec.md §7.
 */
class StartAssignment
{
    public function __construct(private readonly ComplianceEventRecorder $events) {}

    public function handle(UserTrainingAssignment $assignment): CourseAttempt
    {
        return DB::transaction(function () use ($assignment): CourseAttempt {
            $open = $assignment->courseAttempts()
                ->where('status', AttemptStatus::InProgress->value)
                ->orderByDesc('attempt_number')
                ->first();

            if ($open !== null) {
                return $open;
            }

            $attempt = CourseAttempt::query()->create([
                'assignment_id' => $assignment->id,
                'course_version_id' => $assignment->course_version_id,
                'attempt_number' => ((int) $assignment->courseAttempts()->max('attempt_number')) + 1,
                'status' => AttemptStatus::InProgress,
                'started_at' => now(),
            ]);

            if ($assignment->status === AssignmentStatus::Pending) {
                $assignment->update(['status' => AssignmentStatus::InProgress]);
            }

            $this->events->record(ComplianceEventType::CourseStarted, $assignment->user_id, [
                'assignment_id' => $assignment->id,
                'course_version_id' => $assignment->course_version_id,
                'course_attempt_id' => $attempt->id,
                'metadata' => ['attempt_number' => $attempt->attempt_number],
            ]);

            return $attempt;
        });
    }
}
