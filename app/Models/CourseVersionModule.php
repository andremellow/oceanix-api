<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['course_version_id', 'lesson_id', 'module_version_id', 'position', 'is_required'])]
class CourseVersionModule extends Model
{
    protected $table = 'course_version_lessons';

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_required' => 'boolean'];
    }

    public function setModuleVersionIdAttribute(int $value): void
    {
        $this->attributes['lesson_id'] = $value;
    }

    public function getModuleVersionIdAttribute(): ?int
    {
        return isset($this->attributes['lesson_id']) ? (int) $this->attributes['lesson_id'] : null;
    }

    public function courseVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class);
    }

    public function moduleVersion(): BelongsTo
    {
        return $this->belongsTo(ModuleVersion::class, 'lesson_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'lesson_id');
    }
}
