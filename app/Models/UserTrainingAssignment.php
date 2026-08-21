<?php

namespace App\Models;

use App\Enums\AssignmentOrigin;
use App\Enums\AssignmentStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\UserTrainingAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The materialized obligation. It freezes the course version it was issued against, so
 * republishing a course or moving someone between departments never rewrites an existing
 * obligation. See docs/product-spec.md §9.
 */
#[Fillable([
    'user_id', 'course_id', 'course_version_id', 'training_requirement_id', 'origin_type',
    'origin_id', 'series_key', 'cycle_number', 'assigned_at', 'available_at', 'due_at',
    'expires_at', 'status', 'completed_at', 'supersedes_assignment_id', 'metadata',
])]
class UserTrainingAssignment extends Model
{
    /** @use HasFactory<UserTrainingAssignmentFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'origin_type' => AssignmentOrigin::class,
            'assigned_at' => 'datetime',
            'available_at' => 'datetime',
            'due_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'cycle_number' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return BelongsTo<CourseVersion, $this>
     */
    public function courseVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class);
    }

    /**
     * @return BelongsTo<TrainingRequirement, $this>
     */
    public function trainingRequirement(): BelongsTo
    {
        return $this->belongsTo(TrainingRequirement::class);
    }

    /**
     * @return HasMany<CourseAttempt, $this>
     */
    public function courseAttempts(): HasMany
    {
        return $this->hasMany(CourseAttempt::class, 'assignment_id');
    }

    /**
     * @return HasMany<LessonProgress, $this>
     */
    public function lessonProgress(): HasMany
    {
        return $this->hasMany(LessonProgress::class, 'assignment_id');
    }

    /**
     * @return HasMany<ComplianceEvent, $this>
     */
    public function complianceEvents(): HasMany
    {
        return $this->hasMany(ComplianceEvent::class, 'assignment_id');
    }

    /**
     * @return HasOne<Certificate, $this>
     */
    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class, 'assignment_id');
    }

    public function isAvailable(): bool
    {
        return $this->available_at === null || $this->available_at->isPast();
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen()
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    public function daysOverdue(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        return (int) $this->due_at->startOfDay()->diffInDays(now()->startOfDay());
    }

    public function daysUntilDue(): ?int
    {
        if ($this->due_at === null || $this->isOverdue()) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_at->startOfDay());
    }

    /** Percentage of required lessons completed, for the employee progress bar. */
    public function progressPercentage(): int
    {
        $required = $this->courseVersion?->requiredLessonCount() ?? 0;

        if ($required === 0) {
            return $this->status->isSatisfied() ? 100 : 0;
        }

        $completed = $this->lessonProgress()->whereNotNull('completed_at')->count();

        return (int) min(100, round($completed / $required * 100));
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', array_column(AssignmentStatus::open(), 'value'));
    }

    public function scopeOverdue(Builder $query): void
    {
        $query->open()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    public function scopeDueWithin(Builder $query, int $days): void
    {
        $query->open()
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [now(), now()->addDays($days)]);
    }
}
