<?php

namespace App\Actions\Courses;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Exceptions\CoursePublicationException;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use App\Services\Audit\AuditLogger;
use App\Services\Modules\ModuleLineageLock;
use Illuminate\Support\Facades\DB;

/**
 * Editing a published version means cloning it into a new draft: the published edition is
 * evidence and is never touched. Videos are reused by provider asset id — the same asset
 * can back several versions, so nothing is re-uploaded or re-encoded.
 */
class CreateDraftFromVersion
{
    public function __construct(private readonly AuditLogger $audit, private readonly ?ModuleLineageLock $lineageLock = null) {}

    public function handle(CourseVersion $source, ?Account $platformActor = null): CourseVersion
    {
        return DB::transaction(function () use ($source, $platformActor): CourseVersion {
            $sourceCourseId = CourseVersion::query()->whereKey($source->id)->firstOrFail(['course_id'])->course_id;
            $course = Course::query()->lockForUpdate()->findOrFail($sourceCourseId);
            $lockedSource = CourseVersion::query()->lockForUpdate()->findOrFail($source->id);

            if ($course->status === CourseStatus::Archived) {
                throw new \LogicException('Archived courses cannot create new draft versions.');
            }

            if ((int) $lockedSource->course_id !== (int) $course->id) {
                throw new \LogicException('The source version does not belong to this course.');
            }

            if ($course->is_shared && $course->company_id === null) {
                $platformActor = Account::query()->whereKey($platformActor?->id)
                    ->where('is_platform_admin', true)->where('status', 'active')->first();
                if ($platformActor === null) {
                    throw new \LogicException('An active platform administrator is required.');
                }
            }

            if ($course->versions()->where('status', CourseVersionStatus::Draft->value)
                ->where('publication_kind', 'manual')->exists()) {
                throw CoursePublicationException::draftAlreadyExists();
            }

            $compositions = $lockedSource->moduleCompositions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            if ($compositions->isNotEmpty()) {
                $requestedIds = $compositions->pluck('lesson_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values();
                $modules = ($this->lineageLock ?? app(ModuleLineageLock::class))->versions($requestedIds)
                    ->whereIn('id', $requestedIds)->keyBy('id');
                $compositions->each(fn (CourseVersionModule $composition) => $composition->setRelation('moduleVersion', $modules->get($composition->lesson_id)));
            }

            if ($course->is_shared && $compositions->contains(fn (CourseVersionModule $composition): bool => $composition->moduleVersion === null
                || ! $composition->moduleVersion->is_shared
                || $composition->moduleVersion->company_id !== null
                || $composition->moduleVersion->lineage_archived_at !== null)) {
                throw new \LogicException('A shared course contains an ineligible module reference.');
            }

            $draft = CourseVersion::query()->create([
                'course_id' => $course->id,
                'version_number' => ((int) $course->versions()->max('version_number')) + 1,
                'status' => CourseVersionStatus::Draft,
                'title' => $lockedSource->title,
                'description' => $lockedSource->description,
                'completion_rule' => $lockedSource->completion_rule,
                'publication_kind' => 'manual',
                'source_course_version_id' => $lockedSource->id,
            ]);

            if ($compositions->isNotEmpty()) {
                foreach ($compositions as $composition) {
                    CourseVersionModule::query()->create([
                        'course_version_id' => $draft->id,
                        'module_version_id' => $composition->module_version_id,
                        'position' => $composition->position,
                        'is_required' => $composition->is_required,
                    ]);
                }
            }

            foreach ($compositions->isEmpty() ? $lockedSource->lessons()->with(['video', 'questions.options'])->get() : [] as $lesson) {
                $copy = Lesson::query()->create([
                    'course_version_id' => $draft->id,
                    'title' => $lesson->title,
                    'description' => $lesson->description,
                    'content_markdown' => $lesson->content_markdown,
                    'type' => $lesson->type,
                    'position' => $lesson->position,
                    'is_required' => $lesson->is_required,
                    'minimum_watch_percentage' => $lesson->minimum_watch_percentage,
                    'passing_score' => $lesson->passing_score,
                ]);

                if ($lesson->video !== null) {
                    Video::query()->create([
                        'lesson_id' => $copy->id,
                        'provider' => $lesson->video->provider,
                        'provider_asset_id' => $lesson->video->provider_asset_id,
                        'provider_playback_id' => $lesson->video->provider_playback_id,
                        'duration_seconds' => $lesson->video->duration_seconds,
                        'status' => $lesson->video->status,
                        'metadata' => $lesson->video->metadata,
                    ]);
                }

                foreach ($lesson->questions as $question) {
                    $questionCopy = Question::query()->create([
                        'lesson_id' => $copy->id,
                        'type' => $question->type,
                        'prompt' => $question->prompt,
                        'position' => $question->position,
                        'max_attempts' => $question->max_attempts,
                        'weight' => $question->weight,
                    ]);

                    foreach ($question->options as $option) {
                        QuestionOption::query()->create([
                            'question_id' => $questionCopy->id,
                            'text' => $option->text,
                            'is_correct' => $option->is_correct,
                            'position' => $option->position,
                        ]);
                    }
                }
            }

            $this->auditDraft($lockedSource, $draft, $platformActor);

            return $draft->refresh();
        });
    }

    private function auditDraft(CourseVersion $source, CourseVersion $draft, ?Account $platformActor): void
    {
        $this->audit->log('course_version.draft_created', $draft, after: [
            'from_version' => $source->version_number,
            'version_number' => $draft->version_number,
        ], platformActor: $platformActor);
    }
}
