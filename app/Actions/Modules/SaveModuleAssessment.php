<?php

namespace App\Actions\Modules;

use App\Enums\ModuleVersionStatus;
use App\Enums\QuestionType;
use App\Models\Account;
use App\Models\ModuleVersion;
use App\Models\QuestionOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class SaveModuleAssessment
{
    /**
     * @param  array{questions: list<array{id: int, prompt: string, max_attempts: int, options: list<array{id: int, text: string, is_correct: bool}>}>}  $payload
     */
    public function handle(ModuleVersion $version, Account $actor, array $payload, string $expectedRevision): string
    {
        return DB::transaction(function () use ($version, $actor, $payload, $expectedRevision): string {
            $authorizedActor = Account::query()
                ->whereKey($actor->id)
                ->where('is_platform_admin', true)
                ->where('status', 'active')
                ->first();

            if ($authorizedActor === null) {
                throw new LogicException('Only an active platform administrator can edit shared content.');
            }

            $lockedVersion = ModuleVersion::query()->lockForUpdate()->findOrFail($version->id);

            if ($lockedVersion->status !== ModuleVersionStatus::Draft || ! $lockedVersion->is_shared || $lockedVersion->company_id !== null) {
                throw new LogicException('Assessments can only be edited on platform-owned shared module drafts.');
            }

            $validated = Validator::make($payload, [
                'questions' => ['present', 'array'],
                'questions.*.id' => ['required', 'integer'],
                'questions.*.prompt' => ['required', 'string', 'max:1000'],
                'questions.*.max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
                'questions.*.options' => ['required', 'array', 'min:2'],
                'questions.*.options.*.id' => ['required', 'integer'],
                'questions.*.options.*.text' => ['required', 'string', 'max:1000'],
                'questions.*.options.*.is_correct' => ['required', 'boolean'],
            ])->validate();

            $questions = $lockedVersion->questions()->lockForUpdate()->get();
            $optionsByQuestion = QuestionOption::query()
                ->whereIn('question_id', $questions->pluck('id'))
                ->orderBy('position')
                ->lockForUpdate()
                ->get()
                ->groupBy('question_id');
            $questions->each(fn ($question) => $question->setRelation('options', $optionsByQuestion->get($question->id, collect())));

            if (! hash_equals($this->revisionFrom($questions), $expectedRevision)) {
                throw ValidationException::withMessages([
                    'revision' => __('This assessment changed elsewhere. Reload the page before saving again.'),
                ]);
            }

            $submittedQuestions = collect($validated['questions']);

            if ($submittedQuestions->pluck('id')->sort()->values()->all() !== $questions->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['questions' => __('One or more assessment questions are unavailable.')]);
            }

            foreach ($questions as $question) {
                $questionIndex = $submittedQuestions->search(fn (array $item): bool => $item['id'] === $question->id);
                $submitted = $submittedQuestions[$questionIndex];
                $options = collect($submitted['options']);

                if ($options->pluck('id')->sort()->values()->all() !== $question->options->pluck('id')->sort()->values()->all()) {
                    throw ValidationException::withMessages(["questions.{$questionIndex}.options" => __('One or more assessment answers are unavailable.')]);
                }

                if ($question->type === QuestionType::SingleChoice && $options->where('is_correct', true)->count() !== 1) {
                    throw ValidationException::withMessages(["questions.{$questionIndex}.options" => __('Choose exactly one correct answer.')]);
                }
            }

            foreach ($questions as $question) {
                $submitted = $submittedQuestions->firstWhere('id', $question->id);
                $question->update([
                    'prompt' => trim($submitted['prompt']),
                    'max_attempts' => $submitted['max_attempts'],
                ]);

                foreach ($submitted['options'] as $optionData) {
                    $question->options->firstWhere('id', $optionData['id'])->update([
                        'text' => trim($optionData['text']),
                        'is_correct' => $optionData['is_correct'],
                    ]);
                }
            }

            return $this->revision($lockedVersion);
        });
    }

    public function revision(ModuleVersion $version): string
    {
        return $this->revisionFrom($version->questions()->with('options')->get());
    }

    private function revisionFrom($questions): string
    {
        $content = $questions->sortBy('position')->values()->map(fn ($question): array => [
            'id' => $question->id,
            'prompt' => $question->prompt,
            'max_attempts' => $question->max_attempts,
            'options' => $question->options->sortBy('position')->values()->map(fn ($option): array => [
                'id' => $option->id,
                'text' => $option->text,
                'is_correct' => (bool) $option->is_correct,
            ])->all(),
        ])->all();

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
    }
}
