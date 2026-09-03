<?php

namespace App\Models;

use App\Enums\ModuleVersionStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Backwards-compatible class name while module versions live directly in `lessons`. */
class ModuleVersion extends Module
{
    public function isFillable($key): bool
    {
        return $key === 'module_id' || parent::isFillable($key);
    }

    protected function casts(): array
    {
        return [...parent::casts(), 'status' => ModuleVersionStatus::class];
    }

    public function setModuleIdAttribute(int $value): void
    {
        $module = Module::query()->findOrFail($value);
        $this->attributes['source_lesson_id'] = $module->id;
        $this->attributes['company_id'] = $module->company_id;
        $this->attributes['is_shared'] = $module->is_shared;
        $this->attributes['code'] = $module->code;
        $this->attributes['lineage_uuid'] = $module->lineage_uuid;
    }

    public function getModuleIdAttribute(): ?int
    {
        return isset($this->attributes['source_lesson_id']) ? (int) $this->attributes['source_lesson_id'] : null;
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'source_lesson_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): bool {
            if ($version->getRawOriginal('status') !== ModuleVersionStatus::Published->value) {
                return true;
            }

            $dirty = array_keys($version->getDirty());

            if ($dirty === ['lineage_archived_at']) {
                return true;
            }

            return $dirty === ['status']
                && in_array($version->status, [ModuleVersionStatus::Retired, ModuleVersionStatus::Archived], true);
        });

        static::deleting(fn (self $version): bool => $version->status !== ModuleVersionStatus::Published);
    }

    public function platformPublisher(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'published_by_account_id');
    }

    public function isEditable(): bool
    {
        return $this->status === ModuleVersionStatus::Draft;
    }
}
