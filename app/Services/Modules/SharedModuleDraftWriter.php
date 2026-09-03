<?php

namespace App\Services\Modules;

use App\Enums\ModuleVersionStatus;
use App\Enums\QuestionType;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Services\Courses\LessonContentSanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class SharedModuleDraftWriter
{
    public function __construct(private readonly LessonContentSanitizer $sanitizer) {}

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

        $questions = $module->questions()->lockForUpdate()->get();
        $options = QuestionOption::query()->whereIn('question_id', $questions->pluck('id'))->orderBy('position')->lockForUpdate()->get()->groupBy('question_id');
        $questions->each(fn ($question) => $question->setRelation('options', $options->get($question->id, collect())));

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
            'questions.*.id' => ['present', 'nullable', 'integer'],
            'questions.*.prompt' => ['required', 'string', 'max:1000'],
            'questions.*.type' => ['required', 'string', 'in:single_choice,multiple_choice'],
            'questions.*.max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'questions.*.options' => ['required', 'array', 'min:2'],
            'questions.*.options.*.id' => ['present', 'nullable', 'integer'],
            'questions.*.options.*.text' => ['required', 'string', 'max:1000'],
            'questions.*.options.*.is_correct' => ['required', 'boolean'],
        ])->validate();

        if ($data['id'] !== $module->id) {
            throw ValidationException::withMessages(['modules' => __('One or more modules are unavailable.')]);
        }

        $submitted = collect($data['questions']);
        $submittedQuestionIds = $submitted->pluck('id')->filter()->values();
        if ($submittedQuestionIds->duplicates()->isNotEmpty() || $submittedQuestionIds->diff($questions->pluck('id'))->isNotEmpty()) {
            throw ValidationException::withMessages(['questions' => __('One or more assessment questions are unavailable.')]);
        }

        foreach ($submitted as $index => $questionData) {
            $answers = collect($questionData['options']);
            $submittedOptionIds = $answers->pluck('id')->filter()->values();
            if ($submittedOptionIds->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(["questions.{$index}.options" => __('One or more assessment answers are unavailable.')]);
            }
            if ($questionData['id'] !== null) {
                $question = $questions->firstWhere('id', $questionData['id']);
                if ($question === null || $submittedOptionIds->diff($question->options->pluck('id'))->isNotEmpty()) {
                    throw ValidationException::withMessages(["questions.{$index}.options" => __('One or more assessment answers are unavailable.')]);
                }
            } elseif ($submittedOptionIds->isNotEmpty()) {
                throw ValidationException::withMessages(["questions.{$index}.options" => __('One or more assessment answers are unavailable.')]);
            }
            if (QuestionType::from($questionData['type']) === QuestionType::SingleChoice && $answers->where('is_correct', true)->count() !== 1) {
                throw ValidationException::withMessages(["questions.{$index}.options" => __('Choose exactly one correct answer.')]);
            }
        }

        return ['module' => $module, 'data' => $data, 'questions' => $questions];
    }

    public function write(array $prepared): void
    {
        $module = $prepared['module'];
        $data = $prepared['data'];
        $moduleFields = ['title', 'description', 'minimum_watch_percentage', 'passing_score'];
        if ($data['content_dirty']) {
            $moduleFields[] = 'content_markdown';
        }
        $module->update(collect($data)->only($moduleFields)->all());
        $submitted = collect($data['questions']);
        foreach ($prepared['questions']->whereNotIn('id', $submitted->pluck('id')->filter()) as $removedQuestion) {
            $removedQuestion->delete();
        }

        foreach ($submitted->values() as $questionIndex => $questionData) {
            $attributes = ['prompt' => trim($questionData['prompt']), 'type' => QuestionType::from($questionData['type']), 'max_attempts' => $questionData['max_attempts'], 'position' => $questionIndex + 1];
            $question = $questionData['id'] === null
                ? Question::query()->create([...$attributes, 'company_id' => null, 'lesson_id' => $module->id])
                : $prepared['questions']->firstWhere('id', $questionData['id']);
            if ($questionData['id'] !== null) {
                $question->update($attributes);
            }

            $existingOptions = $questionData['id'] === null ? collect() : $question->options;
            foreach ($existingOptions->whereNotIn('id', collect($questionData['options'])->pluck('id')->filter()) as $removedOption) {
                $removedOption->delete();
            }
            foreach (collect($questionData['options'])->values() as $optionIndex => $answer) {
                $attributes = ['text' => trim($answer['text']), 'is_correct' => $answer['is_correct'], 'position' => $optionIndex + 1];
                $option = $answer['id'] === null
                    ? QuestionOption::query()->create([...$attributes, 'company_id' => null, 'question_id' => $question->id])
                    : $existingOptions->firstWhere('id', $answer['id']);
                if ($answer['id'] !== null) {
                    $option->update($attributes);
                }
            }
        }
    }

    private function revisionFrom(ModuleVersion $module, Collection $questions): string
    {
        return hash('sha256', json_encode([
            'module' => collect($module->only(['id', 'title', 'description', 'content_markdown', 'minimum_watch_percentage', 'passing_score']))->all(),
            'questions' => $questions->sortBy('position')->values()->map(fn ($question): array => [
                'id' => $question->id, 'prompt' => $question->prompt, 'type' => $question->type->value, 'max_attempts' => $question->max_attempts,
                'options' => $question->options->sortBy('position')->values()->map(fn ($option): array => ['id' => $option->id, 'text' => $option->text, 'is_correct' => (bool) $option->is_correct])->all(),
            ])->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
