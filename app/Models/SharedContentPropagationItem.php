<?php

namespace App\Models;

use App\Enums\SharedContentPropagationItemStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['propagation_id', 'course_id', 'company_id', 'status', 'source_course_version_id', 'result_course_version_id', 'attempt_count', 'last_error', 'started_at', 'completed_at'])]
class SharedContentPropagationItem extends Model
{
    protected function casts(): array
    {
        return ['status' => SharedContentPropagationItemStatus::class, 'attempt_count' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function propagation(): BelongsTo
    {
        return $this->belongsTo(SharedContentPropagation::class, 'propagation_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sourceCourseVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class, 'source_course_version_id');
    }

    public function resultCourseVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class, 'result_course_version_id');
    }
}
