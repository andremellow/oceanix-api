<?php

namespace App\Actions\Modules;

use App\Enums\ModuleVersionStatus;
use App\Models\Account;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use LogicException;

class CreateModuleDraft
{
    public function handle(ModuleVersion $source, Account $actor): ModuleVersion
    {
        if (! $actor->is_platform_admin || ! $source->is_shared || $source->status->value === 'archived') {
            throw new LogicException('Only a platform administrator can edit shared content.');
        }

        if (ModuleVersion::query()->where('lineage_uuid', $source->lineage_uuid)
            ->where('status', ModuleVersionStatus::Draft->value)->exists()) {
            throw new LogicException('This module already has an open draft version.');
        }

        return DB::transaction(function () use ($source, $actor): ModuleVersion {
            $draft = ModuleVersion::query()->create([
                'company_id' => null,
                'course_version_id' => null,
                'is_shared' => true,
                'code' => $source->code,
                'lineage_uuid' => $source->lineage_uuid,
                'version_number' => ((int) ModuleVersion::query()->where('lineage_uuid', $source->lineage_uuid)->max('version_number')) + 1,
                'status' => ModuleVersionStatus::Draft,
                'title' => $source->title,
                'description' => $source->description,
                'type' => $source->type,
                'position' => $source->position,
                'is_required' => $source->is_required,
                'minimum_watch_percentage' => $source->minimum_watch_percentage,
                'passing_score' => $source->passing_score,
                'source_lesson_id' => $source->id,
                'published_by_account_id' => $actor->id,
            ]);

            $source->load(['video', 'questions.options']);
            if ($source->video !== null) {
                Video::query()->create([
                    'company_id' => null, 'lesson_id' => $draft->id, 'provider' => $source->video->provider,
                    'provider_asset_id' => $source->video->provider_asset_id,
                    'provider_playback_id' => $source->video->provider_playback_id,
                    'duration_seconds' => $source->video->duration_seconds,
                    'status' => $source->video->status, 'metadata' => $source->video->metadata,
                ]);
            }
            foreach ($source->questions as $question) {
                $questionCopy = Question::query()->create([
                    'company_id' => null, 'lesson_id' => $draft->id, 'type' => $question->type,
                    'prompt' => $question->prompt, 'position' => $question->position,
                    'max_attempts' => $question->max_attempts, 'weight' => $question->weight,
                ]);
                foreach ($question->options as $option) {
                    QuestionOption::query()->create([
                        'company_id' => null, 'question_id' => $questionCopy->id, 'text' => $option->text,
                        'is_correct' => $option->is_correct, 'position' => $option->position,
                    ]);
                }
            }

            return $draft->refresh();
        });
    }
}
