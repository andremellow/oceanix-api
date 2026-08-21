<?php

namespace App\Models;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'title', 'description', 'status', 'current_published_version_id'])]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return ['status' => CourseStatus::class];
    }

    /**
     * @return HasMany<CourseVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CourseVersion::class)->orderByDesc('version_number');
    }

    /**
     * @return BelongsTo<CourseVersion, $this>
     */
    public function currentPublishedVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class, 'current_published_version_id');
    }

    /**
     * @return HasMany<TrainingRequirement, $this>
     */
    public function trainingRequirements(): HasMany
    {
        return $this->hasMany(TrainingRequirement::class);
    }

    /**
     * @return HasMany<UserTrainingAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserTrainingAssignment::class);
    }

    public function draftVersion(): ?CourseVersion
    {
        return $this->versions()->where('status', CourseVersionStatus::Draft->value)->first();
    }

    public function scopeAssignable(Builder $query): void
    {
        $query->where('status', CourseStatus::Active->value)
            ->whereNotNull('current_published_version_id');
    }
}
