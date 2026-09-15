<?php

namespace App\Services\Modules;

use App\Enums\ModuleVersionStatus;
use App\Enums\QuestionType;
use App\Models\ModuleVersion;
use App\Models\QuestionOption;
use App\Models\Video;
use App\Services\CourseEditor\EditorRevision;
use App\Services\Courses\LessonContentSanitizer;
use App\Services\Documents\LessonDocumentLinks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class SharedModuleDraftWriter
{
    public function __construct(
        private readonly LessonContentSanitizer $sanitizer,
        private readonly EditorRevision $revisions,
    ) {}

    public function revision(ModuleVersion $module): string
    {
        $questions = $module->questions()->with('options')->get();

        return $this->revisionFrom($module, $questions);
    }

    /** @return array{module: ModuleVersion, data: array, questions: Collection} */
    public function prepare(ModuleVersion $module, array $payload, string $expectedRevision): array
    {
        if ($module->status !== ModuleVersionStatus::Draft || ! $module->is_shared || $module->company_id !== null) {
            throw new LogicException('Only platform-owned shared module drafts can be saved.');
        }

        $questions = $module->questions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
        $options = QuestionOption::query()->whereIn('question_id', $questions->pluck('id'))->orderBy('question_id')->orderBy('position')->orderBy('id')->lockForUpdate()->get()->groupBy('question_id');
        $questions->each(fn ($question) => $question->setRelation('options', $options->get($question->id, collect())));
        $videos = Video::query()->where('lesson_id', $module->id)->orderBy('id')->lockForUpdate()->get();
        $module->setRelation('video', $videos->where('is_current', true)->last());

        if (! hash_equals($this->revisionFrom($module, $questions), $expectedRevision)) {
            throw ValidationException::withMessages(['revision' => __('This module changed elsewhere. Reload the page before saving again.')]);
        }

        $payload['content_dirty'] ??= false;
        if ($payload['content_dirty']) {
            $payload['content_markdown'] = $this->sanitizer->sanitize((string) ($payload['content_markdown'] ?? ''));
        }
        $data = Validator::make($payload, [
            'id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'content_markdown' => ['nullable', 'string', 'max:100000'],
            'content_dirty' => ['required', 'boolean'],
            'minimum_watch_percentage' => ['required', 'integer', 'min:1', 'max:100'],
            'passing_score' => ['required', 'integer', 'min:1', 'max:100'],
            'questions' => ['present', 'array'],
            'questions.*.id' => ['required', 'integer'],
            'questions.*.prompt' => ['required', 'string', 'max:1000'],
            'questions.*.type' => ['required', 'string', 'in:single_choice,multiple_choice'],
            'questions.*.max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'questions.*.options' => ['required', 'array', 'min:2'],
            'questions.*.options.*.id' => ['required', 'integer'],
            'questions.*.options.*.text' => ['required', 'string', 'max:1000'],
            'questions.*.options.*.is_correct' => ['required', 'boolean'],
        ])->validate();

        if ($data['id'] !== $module->id) {
            throw ValidationException::withMessages(['modules' => __('One or more modules are unavailable.')]);
        }

        if ($data['content_dirty']) {
            app(LessonDocumentLinks::class)->validate($module, (string) ($data['content_markdown'] ?? ''));
        }

        $submitted = collect($data['questions']);
        $submittedQuestionIds = $submitted->pluck('id')->filter()->values();
        if ($submittedQuestionIds->duplicates()->isNotEmpty()
            || $submittedQuestionIds->all() !== $questions->pluck('id')->values()->all()) {
            throw ValidationException::withMessages(['questions' => __('One or more assessment questions are unavailable.')]);
        }

        foreach ($submitted as $index => $questionData) {
            $answers = collect($questionData['options']);
            $submittedOptionIds = $answers->pluck('id')->filter()->values();
            if ($submittedOptionIds->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(["questions.{$index}.options" => __('One or more assessment answers are unavailable.')]);
            }
            $question = $questions->firstWhere('id', $questionData['id']);
            if ($question === null || $submittedOptionIds->all() !== $question->options->pluck('id')->values()->all()) {
                throw ValidationException::withMessages(["questions.{$index}.options" => __('One or more assessment answers are unavailable.')]);
            }
            if (QuestionType::from($questionData['type']) === QuestionType::SingleChoice && $answers->where('is_correct', true)->count() !== 1) {
                throw ValidationException::withMessages(["questions.{$index}.options" => __('Choose exactly one correct answer.')]);
            }
        }

        return ['module' => $module, 'data' => $data, 'questions' => $questions];
    }

    public function write(array $prepared): bool
    {
        $module = $prepared['module'];
        $data = $prepared['data'];
        $changed = false;
        $moduleFields = ['title', 'description', 'minimum_watch_percentage', 'passing_score'];
        if ($data['content_dirty']) {
            $moduleFields[] = 'content_markdown';
        }
        $changed = $this->updateIfChanged($module, collect($data)->only($moduleFields)->all()) || $changed;
        foreach (collect($data['questions'])->values() as $questionData) {
            $attributes = ['prompt' => trim($questionData['prompt']), 'type' => QuestionType::from($questionData['type']), 'max_attempts' => $questionData['max_attempts']];
            $question = $prepared['questions']->firstWhere('id', $questionData['id']);
            $changed = $this->updateIfChanged($question, $attributes) || $changed;

            $existingOptions = $question->options;
            foreach (collect($questionData['options'])->values() as $answer) {
                $attributes = ['text' => trim($answer['text']), 'is_correct' => $answer['is_correct']];
                $changed = $this->updateIfChanged($existingOptions->firstWhere('id', $answer['id']), $attributes) || $changed;
            }
        }

        return $changed;
    }

    private function revisionFrom(ModuleVersion $module, Collection $questions): string
    {
        return $this->revisions->forSharedModule($module, $questions);
    }

    /** @param array<string, mixed> $values */
    private function updateIfChanged($model, array $values): bool
    {
        $model->fill($values);
        if (! $model->isDirty()) {
            return false;
        }
        $model->save();

        return true;
    }
}
