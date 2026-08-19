<?php

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'code', 'status'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_department')
            ->withPivot(['starts_at', 'ends_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<JobFunction, $this>
     */
    public function jobFunctions(): BelongsToMany
    {
        return $this->belongsToMany(JobFunction::class)->withTimestamps();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }
}
