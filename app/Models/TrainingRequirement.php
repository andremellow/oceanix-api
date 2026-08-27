<?php

namespace App\Models;

use App\Enums\CourseStatus;
use App\Enums\FrequencyType;
use App\Enums\RenewalBasis;
use App\Enums\RequirementStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\TrainingRequirementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'course_id', 'name', 'status', 'frequency_type', 'frequency_value', 'renewal_basis',
    'assignment_lead_days', 'due_days_after_assignment', 'effective_from', 'effective_until',
    'created_by',
])]
class TrainingRequirement extends Model
{
    /** @use HasFactory<TrainingRequirementFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => RequirementStatus::class,
            'frequency_type' => FrequencyType::class,
            'renewal_basis' => RenewalBasis::class,
            'frequency_value' => 'integer',
            'assignment_lead_days' => 'integer',
            'due_days_after_assignment' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return HasMany<TrainingRequirementTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(TrainingRequirementTarget::class);
    }

    /**
     * @return HasMany<UserTrainingAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserTrainingAssignment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Active, inside its effective window, and pointing at a published course version. */
    public function isMaterializable(?Carbon $on = null): bool
    {
        $on ??= now();

        if (! $this->status->materializes()) {
            return false;
        }

        if ($this->effective_from !== null && $on->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_until !== null && $on->gt($this->effective_until)) {
            return false;
        }

        $course = $this->course()->first();

        return $course?->status === CourseStatus::Active
            && $course->current_published_version_id !== null;
    }

    public function frequencyLabel(): string
    {
        if (! $this->frequency_type->isRecurring()) {
            return __('Once');
        }

        return __('Every :count :unit', [
            'count' => $this->frequency_value,
            'unit' => strtolower($this->frequency_type->label()),
        ]);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', RequirementStatus::Active->value);
    }
}
