<?php

namespace App\Services\Courses;

use App\Enums\QuestionType;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;

/**
 * Publication readiness. Publishing freezes the content forever — assignments and
 * certificates point at the exact version — so an incomplete version must never reach
 * an employee. See docs/product-spec.md §6 and §20.
 */
class CourseVersionValidator
{
    /**
     * Problems that block publication. An empty list means the version is publishable.
     *
     * @return list<string>
     */
    public function problems(CourseVersion $version): array
    {
        $problems = [];

        $lessons = $version->lessons()->with(['video', 'questions.options'])->get();

        if ($lessons->isEmpty()) {
            return [__('Add at least one lesson before publishing.')];
        }

        foreach ($lessons as $lesson) {
            $problems = [...$problems, ...$this->lessonProblems($lesson)];
        }

        return $problems;
    }

    public function isPublishable(CourseVersion $version): bool
    {
        return $this->problems($version) === [];
    }

    /** @return list<string> */
    private function lessonProblems(Lesson $lesson): array
    {
        $problems = [];
        $label = __('Lesson :position (:title)', ['position' => $lesson->position, 'title' => $lesson->title]);

        if ($lesson->video === null) {
            $problems[] = __(':lesson has no video.', ['lesson' => $label]);
        } elseif (! $lesson->video->isPlayable()) {
            $problems[] = __(':lesson has a video that is not ready yet (:status).', [
                'lesson' => $label,
                'status' => $lesson->video->status->label(),
            ]);
        }

        if ($lesson->questions->isEmpty()) {
            $problems[] = __(':lesson has no questions.', ['lesson' => $label]);

            return $problems;
        }

        foreach ($lesson->questions as $question) {
            $problems = [...$problems, ...$this->questionProblems($label, $question)];
        }

        return $problems;
    }

    /** @return list<string> */
    private function questionProblems(string $lessonLabel, Question $question): array
    {
        $problems = [];
        $label = __(':lesson, question :position', ['lesson' => $lessonLabel, 'position' => $question->position]);
        $correct = $question->options->where('is_correct', true);

        if ($question->options->count() < 2) {
            $problems[] = __(':question needs at least two options.', ['question' => $label]);
        }

        if ($correct->isEmpty()) {
            $problems[] = __(':question has no correct answer.', ['question' => $label]);
        }

        if ($question->type === QuestionType::SingleChoice && $correct->count() > 1) {
            $problems[] = __(':question is single choice but has more than one correct answer.', ['question' => $label]);
        }

        if ($question->options->contains(fn ($option): bool => trim((string) $option->text) === '')) {
            $problems[] = __(':question has an empty option.', ['question' => $label]);
        }

        return $problems;
    }
}
