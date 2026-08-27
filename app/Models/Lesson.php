<?php

namespace App\Models;

use App\Enums\LessonType;
use App\Models\Concerns\HasContentOwnership;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'company_id', 'course_version_id', 'is_shared', 'code', 'lineage_uuid', 'version_number',
    'status', 'published_at', 'published_by_account_id', 'source_lesson_id', 'title', 'description',
    'type', 'position', 'is_required', 'minimum_watch_percentage', 'passing_score',
])]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasContentOwnership, HasFactory;

    protected function casts(): array
    {
        return [
            'type' => LessonType::class,
            'is_shared' => 'boolean',
            'published_at' => 'datetime',
            'is_required' => 'boolean',
            'minimum_watch_percentage' => 'integer',
            'passing_score' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $lesson): void {
            $lesson->code ??= 'module-'.Str::lower((string) Str::ulid());
            $lesson->lineage_uuid ??= (string) Str::uuid();
            $lesson->version_number ??= 1;
            $lesson->status ??= 'draft';
            $lesson->is_shared ??= false;
            $lesson->position ??= 1;
            $lesson->is_required ??= true;
        });

        static::created(function (self $lesson): void {
            if ($lesson->course_version_id !== null) {
                CourseVersionModule::query()->firstOrCreate(
                    ['course_version_id' => $lesson->course_version_id, 'lesson_id' => $lesson->id],
                    ['position' => $lesson->position, 'is_required' => (bool) $lesson->is_required],
                );
            }
        });
    }

    /**
     * @return BelongsTo<CourseVersion, $this>
     */
    public function courseVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class);
    }

    /**
     * @return HasOne<Video, $this>
     */
    public function video(): HasOne
    {
        return $this->hasOne(Video::class, 'lesson_id');
    }

    /**
     * @return HasMany<Question, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'lesson_id')->orderBy('position');
    }
}
