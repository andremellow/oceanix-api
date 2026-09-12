<?php

namespace App\Actions\Videos\Concerns;

use App\Enums\ModuleVersionStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use App\Models\Video;
use App\Services\CourseEditor\EditorRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

trait LocksEditorMediaTarget
{
    /** @return array{lesson: Lesson, platform_actor: ?Account} */
    private function lockEditorMediaTarget(
        Lesson $requested,
        ?User $actor,
        ?Account $platformActor,
        string $expectedRevision,
        EditorRevision $revisions,
    ): array {
        if ($platformActor !== null) {
            return $this->lockSharedMediaTarget($requested, $platformActor, $expectedRevision, $revisions);
        }

        abort_unless($actor instanceof User && $requested->course_version_id !== null, 403);
        $versionId = (int) $requested->course_version_id;
        $courseId = (int) CourseVersion::query()->whereKey($versionId)->value('course_id');
        abort_unless($courseId > 0, 404);

        $course = Course::query()->lockForUpdate()->whereKey($courseId)->whereNotNull('company_id')->where('is_shared', false)->firstOrFail();
        $version = CourseVersion::query()->lockForUpdate()->whereKey($versionId)->where('course_id', $course->id)->firstOrFail();
        Gate::forUser($actor)->authorize('updateVersion', $version);
        if (! $version->isEditable()) {
            throw new AuthorizationException;
        }

        $compositions = $version->moduleCompositions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
        $lessons = $version->lessons()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
        $questions = Question::query()->whereIn('lesson_id', $lessons->pluck('id'))->orderBy('lesson_id')->orderBy('position')->orderBy('id')->lockForUpdate()->get();
        $options = QuestionOption::query()->whereIn('question_id', $questions->pluck('id'))->orderBy('question_id')->orderBy('position')->orderBy('id')->lockForUpdate()->get();
        $videos = Video::query()->whereIn('lesson_id', $lessons->pluck('id'))->orderBy('lesson_id')->orderBy('id')->lockForUpdate()->get();
        $this->hydrateEditorMediaGraph($lessons, $questions, $options, $videos);

        $lesson = $lessons->firstWhere('id', $requested->id);
        abort_unless($lesson instanceof Lesson, 404);
        $this->assertEditorMediaRevision(
            $expectedRevision,
            $revisions->forCompanyCourse($course, $version, $lessons, $compositions),
        );

        return ['lesson' => $lesson, 'platform_actor' => null];
    }

    /** @return array{lesson: ModuleVersion, platform_actor: Account} */
    private function lockSharedMediaTarget(
        Lesson $requested,
        Account $platformActor,
        string $expectedRevision,
        EditorRevision $revisions,
    ): array {
        $authorized = Account::query()->whereKey($platformActor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
        if ($authorized === null) {
            throw new LogicException('Only an active platform administrator can change shared module videos.');
        }

        $rootId = (int) ($requested->source_lesson_id ?: $requested->id);
        $root = Module::query()->lockForUpdate()->findOrFail($rootId);
        $lesson = ModuleVersion::query()->lockForUpdate()->findOrFail($requested->id);
        if (! $root->is_shared
            || $root->company_id !== null
            || ! $lesson->is_shared
            || $lesson->company_id !== null
            || $lesson->lineage_uuid !== $root->lineage_uuid
            || $lesson->status !== ModuleVersionStatus::Draft
            || $lesson->lineage_archived_at !== null) {
            throw new LogicException('Videos can only be changed on platform-owned shared module drafts.');
        }

        $questions = $lesson->questions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
        $options = QuestionOption::query()->whereIn('question_id', $questions->pluck('id'))->orderBy('question_id')->orderBy('position')->orderBy('id')->lockForUpdate()->get();
        $videos = $lesson->videos()->orderBy('id')->lockForUpdate()->get();
        $this->hydrateEditorMediaGraph(collect([$lesson]), $questions, $options, $videos);
        $this->assertEditorMediaRevision($expectedRevision, $revisions->forSharedModule($lesson, $questions));

        return ['lesson' => $lesson, 'platform_actor' => $authorized];
    }

    private function assertEditorMediaRevision(string $expected, string $actual): void
    {
        $this->assertExpectedEditorMediaRevision($expected);
        if (! hash_equals($actual, $expected)) {
            throw ValidationException::withMessages([
                'revision' => __('This draft changed in another session. Your unsaved changes are still here.'),
            ]);
        }
    }

    private function assertExpectedEditorMediaRevision(string $expected): void
    {
        if (blank($expected)) {
            throw ValidationException::withMessages([
                'revision' => __('This draft changed in another session. Your unsaved changes are still here.'),
            ]);
        }
    }

    private function hydrateEditorMediaGraph(Collection $lessons, Collection $questions, Collection $options, Collection $videos): void
    {
        $groupedQuestions = $questions->groupBy('lesson_id');
        $groupedOptions = $options->groupBy('question_id');
        $currentVideos = $videos->where('is_current', true)->groupBy('lesson_id');
        $questions->each(fn (Question $question) => $question->setRelation('options', $groupedOptions->get($question->id, collect())));
        $lessons->each(function (Lesson $lesson) use ($groupedQuestions, $currentVideos): void {
            $lesson->setRelation('questions', $groupedQuestions->get($lesson->id, collect()));
            $lesson->setRelation('video', $currentVideos->get($lesson->id, collect())->last());
        });
    }
}
