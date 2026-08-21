<?php

namespace App\Models;

use App\Enums\TargetScope;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\TrainingRequirementTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit audience rows rather than an arbitrary boolean language: a target is either
 * everyone, a department, a job function, or the intersection of both. Explicit targets
 * are easier to audit and enough for the MVD. See docs/product-spec.md §8.
 */
#[Fillable(['training_requirement_id', 'scope_type', 'department_id', 'job_function_id'])]
class TrainingRequirementTarget extends Model
{
    /** @use HasFactory<TrainingRequirementTargetFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return ['scope_type' => TargetScope::class];
    }

    /**
     * @return BelongsTo<TrainingRequirement, $this>
     */
    public function trainingRequirement(): BelongsTo
    {
        return $this->belongsTo(TrainingRequirement::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<JobFunction, $this>
     */
    public function jobFunction(): BelongsTo
    {
        return $this->belongsTo(JobFunction::class);
    }

    public function describe(): string
    {
        return match ($this->scope_type) {
            TargetScope::Everyone => __('Everyone'),
            TargetScope::Department => $this->department?->name ?? __('Department'),
            TargetScope::JobFunction => $this->jobFunction?->name ?? __('Job function'),
            TargetScope::DepartmentJobFunction => sprintf(
                '%s · %s',
                $this->department?->name ?? __('Department'),
                $this->jobFunction?->name ?? __('Job function'),
            ),
        };
    }
}
