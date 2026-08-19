<?php

namespace App\Services\Training;

use App\Actions\Certificates\IssueCertificate;
use App\Enums\AssignmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\ComplianceEventType;
use App\Models\CourseAttempt;
use App\Models\LessonAttempt;
use App\Models\Question;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Decides when a lesson and a course are finished.
 *
 * The MVD completion rule is "every required lesson completed" — each lesson already
 * carries its own passing score, so no second aggregate threshold is needed.
 * See docs/product-spec.md §6 and §20.
 */
class TrainingCompletionService
{
    public function __construct(
        private readonly ComplianceEventRecorder $events,
        private readonly IssueCertificate $issueCertificate,
    ) {}

    /**
     * Close the lesson attempt if every question has been answered, passing or failing it
     * on the lesson's own score threshold.
     */
    public function evaluateLesson(UserTrainingAssignment $assignment, LessonAttempt $lessonAttempt): LessonAttempt
    {
        if ($lessonAttempt->status !== AttemptStatus::InProgress) {
            return $lessonAttempt;
        }

        $lesson = $lessonAttempt->lesson;
        $questions = $lesson->questions;

        $answered = $questions->filter(fn (Question $question): bool => $lessonAttempt->questionAttempts()
            ->where('question_id', $question->id)
            ->exists());

        if ($answered->count() < $questions->count()) {
            return $lessonAttempt;
        }

        $total = (int) $questions->sum('weight');
        $earned = (int) $questions
            ->filter(fn (Question $question): bool => $lessonAttempt->questionAttempts()
                ->where('question_id', $question->id)
                ->where('is_correct', true)
                ->exists())
            ->sum('weight');

        $score = $total > 0 ? (int) round($earned / $total * 100) : 100;
        $passed = $score >= $lesson->passing_score;

        return DB::transaction(function () use ($assignment, $lessonAttempt, $lesson, $score, $passed): LessonAttempt {
            $lessonAttempt->update([
                'status' => $passed ? AttemptStatus::Passed : AttemptStatus::Failed,
                'completed_at' => now(),
                'score' => $score,
            ]);

            if ($passed) {
                $assignment->lessonProgress()
                    ->where('lesson_id', $lesson->id)
                    ->update(['completed_at' => now()]);
            }

            $this->events->record(
                $passed ? ComplianceEventType::LessonCompleted : ComplianceEventType::LessonFailed,
                $assignment->user_id,
                [
                    'assignment_id' => $assignment->id,
                    'course_version_id' => $assignment->course_version_id,
                    'lesson_id' => $lesson->id,
                    'lesson_attempt_id' => $lessonAttempt->id,
                    'metadata' => ['score' => $score],
                ],
            );

            return $lessonAttempt->refresh();
        });
    }

    /** Completes the assignment once every required lesson is done, and issues the certificate. */
    public function evaluateCourse(UserTrainingAssignment $assignment, CourseAttempt $courseAttempt): UserTrainingAssignment
    {
        $requiredLessonIds = $assignment->courseVersion
            ->lessons()
            ->where('is_required', true)
            ->pluck('id');

        $completed = $assignment->lessonProgress()
            ->whereIn('lesson_id', $requiredLessonIds)
            ->whereNotNull('completed_at')
            ->count();

        if ($requiredLessonIds->isEmpty() || $completed < $requiredLessonIds->count()) {
            return $assignment;
        }

        return DB::transaction(function () use ($assignment, $courseAttempt): UserTrainingAssignment {
            $score = (int) round((float) $courseAttempt->lessonAttempts()
                ->where('status', AttemptStatus::Passed->value)
                ->avg('score'));

            $courseAttempt->update([
                'status' => AttemptStatus::Passed,
                'completed_at' => now(),
                'score' => $score,
            ]);

            $assignment->update([
                'status' => AssignmentStatus::Completed,
                'completed_at' => now(),
            ]);

            $this->events->record(ComplianceEventType::CourseCompleted, $assignment->user_id, [
                'assignment_id' => $assignment->id,
                'course_version_id' => $assignment->course_version_id,
                'course_attempt_id' => $courseAttempt->id,
                'metadata' => ['score' => $score],
            ]);

            $this->issueCertificate->handle($assignment->refresh(), $score);

            return $assignment->refresh();
        });
    }
}
