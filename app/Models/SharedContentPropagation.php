<?php

namespace App\Models;

use App\Enums\SharedContentPropagationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['uuid', 'lesson_id', 'module_version_id', 'initiated_by_account_id', 'restart_in_progress', 'status', 'affected_count', 'not_started_count', 'in_progress_count', 'processed_count', 'succeeded_count', 'failed_count', 'started_at', 'completed_at'])]
class SharedContentPropagation extends Model
{
    protected function casts(): array
    {
        return ['restart_in_progress' => 'boolean', 'status' => SharedContentPropagationStatus::class, 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function moduleVersion(): BelongsTo
    {
        return $this->belongsTo(ModuleVersion::class, 'lesson_id');
    }

    public function setModuleVersionIdAttribute(int $value): void
    {
        $this->attributes['lesson_id'] = $value;
    }

    public function getModuleVersionIdAttribute(): ?int
    {
        return isset($this->attributes['lesson_id']) ? (int) $this->attributes['lesson_id'] : null;
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'initiated_by_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SharedContentPropagationItem::class, 'propagation_id');
    }
}
