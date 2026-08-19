<?php

namespace App\Models;

use App\Enums\AttemptStatus;
use Database\Factories\LessonAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'course_attempt_id', 'lesson_id', 'attempt_number', 'status',
    'started_at', 'completed_at', 'score',
])]
class LessonAttempt extends Model
{
    /** @use HasFactory<LessonAttemptFactory> */
    use HasFactory;

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
     * @return BelongsTo<CourseAttempt, $this>
     */
    public function courseAttempt(): BelongsTo
    {
        return $this->belongsTo(CourseAttempt::class);
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return HasMany<QuestionAttempt, $this>
     */
    public function questionAttempts(): HasMany
    {
        return $this->hasMany(QuestionAttempt::class);
    }
}
