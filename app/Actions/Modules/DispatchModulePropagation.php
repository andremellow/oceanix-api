<?php

namespace App\Actions\Modules;

use App\Enums\AssignmentStatus;
use App\Enums\SharedContentPropagationItemStatus;
use App\Enums\SharedContentPropagationStatus;
use App\Jobs\PropagateSharedModuleToCourse;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\ModuleVersion;
use App\Models\SharedContentPropagation;
use App\Models\UserTrainingAssignment;
use Illuminate\Support\Str;

class DispatchModulePropagation
{
    public function handle(ModuleVersion $previous, ModuleVersion $published, Account $actor, bool $restartInProgress): SharedContentPropagation
    {
        $courseVersions = CourseVersion::query()
            ->whereIn('id', Course::query()->withoutGlobalScopes()->whereNotNull('current_published_version_id')->pluck('current_published_version_id'))
            ->whereHas('moduleCompositions.moduleVersion', fn ($query) => $query
                ->where('lineage_uuid', $published->lineage_uuid)
                ->where('version_number', '<', $published->version_number))
            ->with(['course' => fn ($query) => $query->withoutGlobalScopes()])
            ->get();
        $courseIds = $courseVersions->pluck('course_id');
        $assignments = UserTrainingAssignment::withoutGlobalScope('company')
            ->whereIn('course_id', $courseIds)
            ->whereIn('status', array_column(AssignmentStatus::open(), 'value'));
        $notStarted = (clone $assignments)->whereNotIn('status', [AssignmentStatus::InProgress->value, AssignmentStatus::Failed->value])->count();
        $inProgress = (clone $assignments)->count() - $notStarted;

        $propagation = SharedContentPropagation::query()->create([
            'uuid' => (string) Str::uuid(),
            'lesson_id' => $published->id,
            'initiated_by_account_id' => $actor->id,
            'restart_in_progress' => $restartInProgress,
            'status' => SharedContentPropagationStatus::Pending,
            'affected_count' => $courseVersions->count(),
            'not_started_count' => $notStarted,
            'in_progress_count' => $inProgress,
        ]);

        foreach ($courseVersions as $courseVersion) {
            $item = $propagation->items()->create([
                'course_id' => $courseVersion->course_id,
                'company_id' => $courseVersion->course->company_id,
                'status' => SharedContentPropagationItemStatus::Pending,
                'source_course_version_id' => $courseVersion->id,
            ]);
            PropagateSharedModuleToCourse::dispatch($item->id)->afterCommit();
        }

        if ($courseVersions->isEmpty()) {
            $propagation->update([
                'status' => SharedContentPropagationStatus::Completed,
                'started_at' => now(),
                'completed_at' => now(),
            ]);
        }

        return $propagation->refresh();
    }
}
