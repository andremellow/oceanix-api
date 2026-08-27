<?php

namespace App\Actions\Courses;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Exceptions\CoursePublicationException;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Editing a published version means cloning it into a new draft: the published edition is
 * evidence and is never touched. Videos are reused by provider asset id — the same asset
 * can back several versions, so nothing is re-uploaded or re-encoded.
 */
class CreateDraftFromVersion
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CourseVersion $source): CourseVersion
    {
        $course = $source->course;

        if ($course->status === CourseStatus::Archived) {
            throw new \LogicException('Archived courses cannot create new draft versions.');
        }

        if ($course->versions()->where('status', CourseVersionStatus::Draft->value)->exists()) {
            throw CoursePublicationException::draftAlreadyExists();
        }

        return DB::transaction(function () use ($source, $course): CourseVersion {
            $draft = CourseVersion::query()->create([
                'course_id' => $course->id,
                'version_number' => ((int) $course->versions()->max('version_number')) + 1,
                'status' => CourseVersionStatus::Draft,
                'title' => $source->title,
                'description' => $source->description,
                'completion_rule' => $source->completion_rule,
            ]);

            $compositions = method_exists($source, 'moduleCompositions')
                ? $source->moduleCompositions()->get()
                : collect();

            if ($compositions->isNotEmpty()) {
                foreach ($compositions->load('moduleVersion') as $composition) {
                    if (! $composition->moduleVersion?->is_shared) {
                        continue;
                    }

                    CourseVersionModule::query()->create([
                        'course_version_id' => $draft->id,
                        'module_version_id' => $composition->module_version_id,
                        'position' => $composition->position,
                        'is_required' => $composition->is_required,
                    ]);
                }
            }

            foreach ($source->lessons()->with(['video', 'questions.options'])->get() as $lesson) {
                $copy = Lesson::query()->create([
                    'course_version_id' => $draft->id,
                    'title' => $lesson->title,
                    'description' => $lesson->description,
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

            $this->auditDraft($source, $draft);

            return $draft->refresh();
        });
    }

    private function auditDraft(CourseVersion $source, CourseVersion $draft): void
    {
        $this->audit->log('course_version.draft_created', $draft, after: [
            'from_version' => $source->version_number,
            'version_number' => $draft->version_number,
        ]);
    }
}
