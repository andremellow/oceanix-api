<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['course_id', 'associated_at', 'associated_by_user_id', 'removed_at', 'removed_by_user_id', 'removal_reason'])]
class CompanyCourse extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['associated_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function associatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'associated_by_user_id');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }
}
