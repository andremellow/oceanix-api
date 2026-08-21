<?php

namespace App\Models;

use App\Enums\AttemptStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\CourseAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'assignment_id', 'course_version_id', 'attempt_number', 'status',
    'started_at', 'completed_at', 'score',
])]
class CourseAttempt extends Model
{
    /** @use HasFactory<CourseAttemptFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempt_number' => 'integer',
            'score' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<UserTrainingAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(UserTrainingAssignment::class, 'assignment_id');
    }

    /**
     * @return BelongsTo<CourseVersion, $this>
     */
    public function courseVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class);
    }

    /**
     * @return HasMany<LessonAttempt, $this>
     */
    public function lessonAttempts(): HasMany
    {
        return $this->hasMany(LessonAttempt::class);
    }
}
