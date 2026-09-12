<?php

namespace App\Services\Courses;

use App\Enums\AssignmentStatus;
use App\Models\CourseVersion;
use App\Models\UserTrainingAssignment;

final class CoursePublicationImpact
{
    /** @return array{open: int, pending: int, in_progress: int} */
    public function forVersion(CourseVersion $version): array
    {
        $base = UserTrainingAssignment::query()
            ->where('company_id', $version->course->company_id)
            ->where('course_id', $version->course_id)
            ->where('course_version_id', '!=', $version->id);

        return [
            'open' => (clone $base)->open()->count(),
            'pending' => (clone $base)->where('status', AssignmentStatus::Pending->value)->count(),
            'in_progress' => (clone $base)->where('status', AssignmentStatus::InProgress->value)->count(),
        ];
    }
}
