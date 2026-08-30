<?php

namespace App\Actions\Training;

use App\Enums\AttemptStatus;
use App\Enums\ComplianceEventType;
use App\Enums\QuestionType;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\Question;
use App\Models\QuestionAttempt;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Records one answer to one question.
 *
 * Grading happens here, on the server, against the stored answer key — the client is never
 * told which option is correct before answering. Wrong answers stay in the history; running
 * out of tries fails the lesson attempt. Playback progress remains independent evidence.
 * See docs/product-spec.md §7.
 *
 * @phpstan-type Outcome array{correct: bool, attempts_left: int, lesson_failed: bool}
 */
class AnswerQuestion
{
    public function __construct(
        private readonly ComplianceEventRecorder $events,
    ) {}

    /**
     * @param  list<int>  $selectedOptionIds
     * @return Outcome
     */
    public function handle(
        UserTrainingAssignment $assignment,
        LessonAttempt $lessonAttempt,
        Question $question,
        array $selectedOptionIds,
    ): array {
        $lesson = $question->lesson;

        $this->guard($assignment, $lessonAttempt, $question, $lesson);

        return DB::transaction(function () use ($assignment, $lessonAttempt, $question, $lesson, $selectedOptionIds): array {
            $used = $lessonAttempt->questionAttempts()->where('question_id', $question->id)->count();

            if ($used >= $question->max_attempts) {
                throw new AuthorizationException('No attempts left on this question.');
            }

            // Only options belonging to this question count, whatever the client submitted.
            $selected = $question->options()
                ->whereIn('id', $selectedOptionIds)
                ->pluck('id')
                ->sort()
                ->values();

            $correctIds = collect($question->correctOptionIds())->sort()->values();
            $isCorrect = $question->type === QuestionType::MultipleChoice
                ? $selected->all() === $correctIds->all()
                : $selected->count() === 1 && $correctIds->contains($selected->first());

            QuestionAttempt::query()->create([
                'lesson_attempt_id' => $lessonAttempt->id,
                'question_id' => $question->id,
                'attempt_number' => $used + 1,
                'selected_option_ids' => $selected->all(),
                'is_correct' => $isCorrect,
                'answered_at' => now(),
            ]);

            $this->events->record(ComplianceEventType::QuestionAnswered, $assignment->user_id, [
                'assignment_id' => $assignment->id,
                'course_version_id' => $assignment->course_version_id,
                'lesson_id' => $lesson->id,
                'lesson_attempt_id' => $lessonAttempt->id,
                'question_id' => $question->id,
                'metadata' => ['attempt_number' => $used + 1, 'is_correct' => $isCorrect],
            ]);

            $this->events->record(
                $isCorrect ? ComplianceEventType::QuestionPassed : ComplianceEventType::QuestionFailed,
                $assignment->user_id,
                [
                    'assignment_id' => $assignment->id,
                    'lesson_id' => $lesson->id,
                    'lesson_attempt_id' => $lessonAttempt->id,
                    'question_id' => $question->id,
                ],
            );

            $attemptsLeft = max(0, $question->max_attempts - ($used + 1));
            $lessonFailed = false;

            if (! $isCorrect && $attemptsLeft === 0) {
                $this->failLesson($assignment, $lessonAttempt, $lesson);
                $lessonFailed = true;
            }

            return [
                'correct' => $isCorrect,
                'attempts_left' => $attemptsLeft,
                'lesson_failed' => $lessonFailed,
            ];
        });
    }

    private function guard(
        UserTrainingAssignment $assignment,
        LessonAttempt $lessonAttempt,
        Question $question,
        Lesson $lesson,
    ): void {
        if ($lessonAttempt->courseAttempt->assignment_id !== $assignment->id) {
            throw new AuthorizationException('This attempt does not belong to the assignment.');
        }

        if ($lessonAttempt->lesson_id !== $lesson->id) {
            throw new AuthorizationException('This question does not belong to the lesson being attempted.');
        }

        if (! $assignment->includesLesson($lesson)) {
            throw new AuthorizationException('This lesson does not belong to the assigned course version.');
        }

        if ($lessonAttempt->status !== AttemptStatus::InProgress) {
            throw new AuthorizationException('This lesson attempt is already finished.');
        }

    }

    private function failLesson(UserTrainingAssignment $assignment, LessonAttempt $lessonAttempt, Lesson $lesson): void
    {
        $lessonAttempt->update([
            'status' => AttemptStatus::Failed,
            'completed_at' => now(),
            'score' => $this->score($lessonAttempt),
        ]);

        $this->events->record(ComplianceEventType::LessonFailed, $assignment->user_id, [
            'assignment_id' => $assignment->id,
            'lesson_id' => $lesson->id,
            'lesson_attempt_id' => $lessonAttempt->id,
        ]);

    }

    private function score(LessonAttempt $lessonAttempt): int
    {
        $questions = $lessonAttempt->lesson->questions;
        $total = (int) $questions->sum('weight');

        if ($total === 0) {
            return 0;
        }

        $earned = $questions
            ->filter(fn (Question $question): bool => $lessonAttempt->questionAttempts()
                ->where('question_id', $question->id)
                ->where('is_correct', true)
                ->exists())
            ->sum('weight');

        return (int) round($earned / $total * 100);
    }
}
