<?php

namespace App\Services\Modules;

use App\Enums\QuestionType;
use App\Enums\VideoStatus;
use App\Models\Lesson;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Services\Courses\LessonContentRenderer;

class ModuleVersionValidator
{
    public function __construct(private readonly LessonContentRenderer $contentRenderer) {}

    /** @return list<string> */
    public function problems(ModuleVersion $version): array
    {
        $version->load(['video', 'questions.options']);

        return $this->lessonProblems($version);
    }

    public function isPublishable(ModuleVersion $version): bool
    {
        return $this->problems($version) === [];
    }

    /** @return list<string> */
    private function lessonProblems(Lesson $lesson): array
    {
        $label = __('Lesson :position (:title)', ['position' => $lesson->position, 'title' => $lesson->title]);
        $problems = [];

        $containsVideo = $this->contentRenderer->containsVideo((string) $lesson->content_markdown);

        if ($containsVideo && $lesson->video === null) {
            $problems[] = __(':lesson has no video.', ['lesson' => $label]);
        } elseif ($containsVideo && ! $lesson->video->isPlayable()) {
            $problems[] = __(':lesson has a video that is not ready yet (:status).', ['lesson' => $label, 'status' => $lesson->video->status->label()]);
        }

        $latestGeneration = (int) $lesson->videos()->max('replacement_generation');
        $cutoff = now()->subMinutes((int) config('oceanix.video_upload_expiry_minutes', 120));
        if ($containsVideo && $lesson->videos()->where('replacement_generation', $latestGeneration)->where(function ($query) use ($cutoff): void {
            $query->where('status', VideoStatus::Processing->value)
                ->orWhere(fn ($query) => $query->where('status', VideoStatus::Uploading->value)->where('created_at', '>', $cutoff));
        })->exists()) {
            $problems[] = __(':lesson has a video replacement that is still processing.', ['lesson' => $label]);
        }

        if ($lesson->questions->isEmpty()) {
            return [...$problems, __(':lesson has no questions.', ['lesson' => $label])];
        }

        foreach ($lesson->questions as $question) {
            $problems = [...$problems, ...$this->questionProblems($label, $question)];
        }

        return $problems;
    }

    /** @return list<string> */
    private function questionProblems(string $lessonLabel, Question $question): array
    {
        $label = __(':lesson, question :position', ['lesson' => $lessonLabel, 'position' => $question->position]);
        $correct = $question->options->where('is_correct', true);
        $problems = [];

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
