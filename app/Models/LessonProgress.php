<?php

namespace App\Models;

use Database\Factories\LessonProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operational read model projected from compliance events. Safe to rebuild; it is never
 * the evidence of record.
 */
#[Table('lesson_progress')]
#[Fillable([
    'assignment_id', 'lesson_id', 'started_at', 'last_position_seconds',
    'watched_seconds', 'percentage_watched', 'completed_at',
])]
class LessonProgress extends Model
{
    /** @use HasFactory<LessonProgressFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_position_seconds' => 'integer',
            'watched_seconds' => 'integer',
            'percentage_watched' => 'integer',
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
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** The assessment unlocks only once the lesson's watch threshold is reached. */
    public function meetsWatchThreshold(): bool
    {
        return $this->percentage_watched >= ($this->lesson?->minimum_watch_percentage ?? 100);
    }
}
