<?php

namespace App\Services\Modules;

use App\Enums\AssignmentStatus;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\ModuleVersion;
use App\Models\UserTrainingAssignment;

class ModulePropagationImpact
{
    /** @return array{affected_courses: int, not_started_assignments: int, in_progress_assignments: int} */
    public function summarize(ModuleVersion $version): array
    {
        $courseVersionIds = CourseVersion::query()->withoutGlobalScopes()
            ->whereIn('id', Course::query()->withoutGlobalScopes()->whereNotNull('current_published_version_id')->select('current_published_version_id'))
            ->whereHas('moduleCompositions.moduleVersion', fn ($query) => $query
                ->where('lineage_uuid', $version->lineage_uuid))
            ->pluck('id');

        $assignments = UserTrainingAssignment::query()->withoutGlobalScopes()
            ->whereIn('course_version_id', $courseVersionIds)
            ->whereIn('status', array_column(AssignmentStatus::open(), 'value'));

        $startedIds = (clone $assignments)
            ->whereIn('status', [AssignmentStatus::InProgress->value, AssignmentStatus::Failed->value])
            ->pluck('id');

        return [
            'affected_courses' => $courseVersionIds->count(),
            'not_started_assignments' => (clone $assignments)->whereNotIn('id', $startedIds)->count(),
            'in_progress_assignments' => $startedIds->count(),
        ];
    }
}
