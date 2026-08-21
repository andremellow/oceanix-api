<?php

namespace App\Models;

use App\Enums\ComplianceEventType;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ComplianceEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Rows are written once and never updated or deleted; the current state lives
 * in the projections (lesson_progress, assignment status). Updates and deletes are blocked
 * at the model level so an accidental save() cannot rewrite evidence.
 */
#[Fillable([
    'uuid', 'event_type', 'user_id', 'assignment_id', 'course_version_id', 'lesson_id',
    'course_attempt_id', 'lesson_attempt_id', 'question_id', 'device_id', 'session_id',
    'occurred_at', 'received_at', 'client_sequence', 'position_seconds', 'metadata',
    'ip_address', 'user_agent',
])]
class ComplianceEvent extends Model
{
    /** @use HasFactory<ComplianceEventFactory> */
    use BelongsToCompany, HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'event_type' => ComplianceEventType::class,
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'client_sequence' => 'integer',
            'position_seconds' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /** True when the device clock disagreed with the server by more than a tolerance. */
    public function hasClockSkew(int $toleranceMinutes = 5): bool
    {
        return $this->occurred_at->diffInMinutes($this->received_at, absolute: true) > $toleranceMinutes;
    }
}
