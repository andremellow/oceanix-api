<?php

namespace App\Actions\Courses;

use App\Models\CourseVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MutateCourseAssessmentStructure
{
    public function handle(CourseVersion $version, User $actor, string $operation, int $parentId, ?int $recordId = null): ?int
    {
        return DB::transaction(function () use ($version, $actor, $operation, $parentId, $recordId): ?int {
            $version = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);
            Gate::forUser($actor)->authorize('updateVersion', $version);

            return match ($operation) {
                'add_question' => $this->addQuestion($version, $parentId),
                'remove_question' => $this->removeQuestion($version, $parentId, $recordId),
                'add_option' => $this->addOption($version, $parentId),
                'remove_option' => $this->removeOption($version, $parentId, $recordId),
                'select_single_correct' => $this->selectSingleCorrect($version, $parentId, $recordId),
                default => throw ValidationException::withMessages(['assessment' => __('ui.assessment_operation_unavailable')]),
            };
        });
    }

    private function addQuestion(CourseVersion $version, int $lessonId): int
    {
        $lesson = $version->lessons()->lockForUpdate()->findOrFail($lessonId);
        $question = Question::query()->create(['lesson_id' => $lesson->id, 'prompt' => __('New question'), 'position' => ((int) $lesson->questions()->max('position')) + 1]);
        foreach ([1, 2] as $position) {
            QuestionOption::query()->create(['question_id' => $question->id, 'text' => '', 'position' => $position]);
        }

        return $question->id;
    }

    private function removeQuestion(CourseVersion $version, int $lessonId, ?int $questionId): null
    {
        $lesson = $version->lessons()->lockForUpdate()->findOrFail($lessonId);
        $lesson->questions()->lockForUpdate()->findOrFail($questionId)->delete();
        $lesson->questions()->orderBy('position')->get()->each(fn (Question $q, int $i) => $q->update(['position' => $i + 1]));

        return null;
    }

    private function addOption(CourseVersion $version, int $questionId): int
    {
        $question = $this->question($version, $questionId);

        return QuestionOption::query()->create(['question_id' => $question->id, 'text' => '', 'position' => ((int) $question->options()->max('position')) + 1])->id;
    }

    private function removeOption(CourseVersion $version, int $questionId, ?int $optionId): null
    {
        $question = $this->question($version, $questionId);
        $question->options()->lockForUpdate()->findOrFail($optionId)->delete();
        $question->options()->orderBy('position')->get()->each(fn (QuestionOption $o, int $i) => $o->update(['position' => $i + 1]));

        return null;
    }

    private function selectSingleCorrect(CourseVersion $version, int $questionId, ?int $optionId): null
    {
        $question = $this->question($version, $questionId);
        $option = $question->options()->lockForUpdate()->findOrFail($optionId);
        $question->options()->update(['is_correct' => false]);
        $option->update(['is_correct' => true]);

        return null;
    }

    private function question(CourseVersion $version, int $id): Question
    {
        return Question::query()->whereHas('lesson', fn ($q) => $q->where('course_version_id', $version->id))->lockForUpdate()->findOrFail($id);
    }
}
