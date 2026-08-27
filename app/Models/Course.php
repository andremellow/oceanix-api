<?php

namespace App\Models;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Models\Concerns\HasContentOwnership;
use App\Tenancy\TenantContext;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'is_shared', 'code', 'title', 'description', 'status', 'current_published_version_id'])]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasContentOwnership, HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope('company-library', function (Builder $query): void {
            if (request()->routeIs('platform.*') || session()->has('platform_account_id')) {
                return;
            }

            $company = app(TenantContext::class)->get();

            if ($company === null) {
                return;
            }

            $query->where(function (Builder $query) use ($company): void {
                $query->where($query->qualifyColumn('company_id'), $company->getKey())
                    ->orWhere($query->qualifyColumn('is_shared'), true);
            });
        });
    }

    protected function casts(): array
    {
        return ['is_shared' => 'boolean', 'status' => CourseStatus::class];
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

    public function companyAssociations(): HasMany
    {
        return $this->hasMany(CompanyCourse::class);
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
