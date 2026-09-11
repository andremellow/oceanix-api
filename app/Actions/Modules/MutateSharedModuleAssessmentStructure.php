<?php

namespace App\Actions\Modules;

use App\Enums\ModuleVersionStatus;
use App\Models\Account;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Services\CourseEditor\EditorRevision;
use App\Services\Modules\ModuleLineageLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class MutateSharedModuleAssessmentStructure
{
    public function __construct(
        private readonly ModuleLineageLock $lineageLock,
        private readonly EditorRevision $revisions,
    ) {}

    public function handle(ModuleVersion $version, Account $actor, string $operation, int $parentId, ?int $recordId, string $expectedRevision): ?int
    {
        return DB::transaction(function () use ($version, $actor, $operation, $parentId, $recordId, $expectedRevision): ?int {
            $this->authorize($actor);
            $locked = $this->lineageLock->versions([$version->id])->firstWhere('id', $version->id)
                ?? throw new LogicException('The module is unavailable.');
            if ($locked->status !== ModuleVersionStatus::Draft || ! $locked->is_shared || $locked->company_id !== null || $locked->lineage_archived_at !== null) {
                throw new LogicException('Only active platform-owned shared module drafts can be changed.');
            }
            $questions = $locked->questions()->with('options')->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($this->revisions->forSharedModule($locked, $questions), $expectedRevision)) {
                throw ValidationException::withMessages(['revision' => __('This module changed elsewhere. Reload the page before trying again.')]);
            }

            return match ($operation) {
                'add_question' => $this->addQuestion($locked),
                'remove_question' => $this->removeQuestion($locked, $recordId),
                'add_option' => $this->addOption($locked, $parentId),
                'remove_option' => $this->removeOption($locked, $parentId, $recordId),
                default => throw ValidationException::withMessages(['assessment' => __('This assessment operation is unavailable.')]),
            };
        }, 3);
    }

    private function addQuestion(ModuleVersion $version): int
    {
        $question = Question::query()->create([
            'company_id' => null,
            'lesson_id' => $version->id,
            'prompt' => __('New question'),
            'position' => ((int) $version->questions()->max('position')) + 1,
        ]);
        QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => __('Option 1'), 'position' => 1, 'is_correct' => true]);
        QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => __('Option 2'), 'position' => 2, 'is_correct' => false]);

        return $question->id;
    }

    private function removeQuestion(ModuleVersion $version, ?int $questionId): null
    {
        $version->questions()->lockForUpdate()->findOrFail($questionId)->delete();
        $this->resequence($version->questions()->orderBy('position')->orderBy('id')->lockForUpdate()->get());

        return null;
    }

    private function addOption(ModuleVersion $version, int $questionId): int
    {
        $question = $version->questions()->lockForUpdate()->findOrFail($questionId);

        return QuestionOption::query()->create([
            'company_id' => null,
            'question_id' => $question->id,
            'text' => __('New option'),
            'position' => ((int) $question->options()->max('position')) + 1,
            'is_correct' => false,
        ])->id;
    }

    private function removeOption(ModuleVersion $version, int $questionId, ?int $optionId): null
    {
        $question = $version->questions()->lockForUpdate()->findOrFail($questionId);
        if ($question->options()->count() <= 2) {
            throw ValidationException::withMessages(['options' => __('An assessment question needs at least two answers.')]);
        }
        $question->options()->lockForUpdate()->findOrFail($optionId)->delete();
        $this->resequence($question->options()->orderBy('position')->orderBy('id')->lockForUpdate()->get());

        return null;
    }

    private function resequence($records): void
    {
        $records->values()->each(fn ($record, int $index) => $record->update(['position' => $index + 1]));
    }

    private function authorize(Account $actor): void
    {
        if (! Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->exists()) {
            throw new LogicException('Only an active platform administrator can change shared content.');
        }
    }
}
