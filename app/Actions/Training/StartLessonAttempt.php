<?php

namespace App\Actions\Training;

use App\Enums\AttemptStatus;
use App\Enums\ComplianceEventType;
use App\Models\CourseAttempt;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class StartLessonAttempt
{
    public function __construct(private readonly ComplianceEventRecorder $events) {}

    public function handle(UserTrainingAssignment $assignment, CourseAttempt $courseAttempt, Lesson $lesson): LessonAttempt
    {
        if (! $assignment->includesLesson($lesson)) {
            throw new AuthorizationException('This lesson does not belong to the assigned course version.');
        }

        return DB::transaction(function () use ($assignment, $courseAttempt, $lesson): LessonAttempt {
            $open = $courseAttempt->lessonAttempts()
                ->where('lesson_id', $lesson->id)
                ->where('status', AttemptStatus::InProgress->value)
                ->first();

            if ($open !== null) {
                return $open;
            }

            $attempt = LessonAttempt::query()->create([
                'course_attempt_id' => $courseAttempt->id,
                'lesson_id' => $lesson->id,
                // A failed attempt is never reopened: retrying adds a numbered attempt.
                'attempt_number' => ((int) $courseAttempt->lessonAttempts()
                    ->where('lesson_id', $lesson->id)
                    ->max('attempt_number')) + 1,
                'status' => AttemptStatus::InProgress,
                'started_at' => now(),
            ]);

            $this->events->record(ComplianceEventType::LessonStarted, $assignment->user_id, [
                'assignment_id' => $assignment->id,
                'course_version_id' => $assignment->course_version_id,
                'lesson_id' => $lesson->id,
                'course_attempt_id' => $courseAttempt->id,
                'lesson_attempt_id' => $attempt->id,
                'metadata' => ['attempt_number' => $attempt->attempt_number],
            ]);

            return $attempt;
        });
    }
}
