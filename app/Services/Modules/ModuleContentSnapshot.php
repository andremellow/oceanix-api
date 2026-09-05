<?php

namespace App\Services\Modules;

use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;

/** Compare persisted authoring content, excluding identity and publication state. */
class ModuleContentSnapshot
{
    public function matches(Lesson $source, Lesson $draft): bool
    {
        return $this->capture($source) === $this->capture($draft);
    }

    public function capture(Lesson $module): array
    {
        $module->loadMissing(['video', 'questions.options']);

        return [
            'module' => $module->only([
                'code', 'title', 'description', 'content_markdown', 'type', 'position',
                'is_required', 'minimum_watch_percentage', 'passing_score',
            ]),
            'video' => $module->video?->only([
                'provider', 'provider_asset_id', 'provider_playback_id', 'duration_seconds', 'status', 'metadata',
            ]),
            'questions' => $module->questions->sortBy([['position', 'asc'], ['id', 'asc']])->values()
                ->map(fn (Question $question): array => [
                    ...$question->only(['type', 'prompt', 'position', 'max_attempts', 'weight']),
                    'options' => $question->options->sortBy([['position', 'asc'], ['id', 'asc']])->values()
                        ->map(fn (QuestionOption $option): array => $option->only(['text', 'is_correct', 'position']))->all(),
                ])->all(),
        ];
    }
}
