<?php

namespace App\Models;

use Database\Factories\QuestionAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Wrong answers stay in the history — a new attempt adds a row, never overwrites one. */
#[Fillable([
    'lesson_attempt_id', 'question_id', 'attempt_number',
    'selected_option_ids', 'is_correct', 'answered_at',
])]
class QuestionAttempt extends Model
{
    /** @use HasFactory<QuestionAttemptFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'selected_option_ids' => 'array',
            'is_correct' => 'boolean',
            'answered_at' => 'datetime',
            'attempt_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LessonAttempt, $this>
     */
    public function lessonAttempt(): BelongsTo
    {
        return $this->belongsTo(LessonAttempt::class);
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
