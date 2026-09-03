<?php

namespace App\Models;

use App\Enums\CourseVersionStatus;
use Database\Factories\CourseVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable([
    'course_id', 'version_number', 'status', 'title', 'description',
    'completion_rule', 'published_at', 'published_by', 'published_by_account_id',
    'publication_kind', 'source_course_version_id', 'propagation_item_id',
])]
class CourseVersion extends Model
{
    /** @use HasFactory<CourseVersionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => CourseVersionStatus::class,
            'published_at' => 'datetime',
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
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('position');
    }

    public function moduleCompositions(): HasMany
    {
        return $this->hasMany(CourseVersionModule::class)->orderBy('position');
    }

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_course_version_id');
    }

    public function platformPublisher(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'published_by_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * @return HasMany<UserTrainingAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserTrainingAssignment::class);
    }

    /** A published version is frozen evidence: it is never edited in place. */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function requiredLessonCount(): int
    {
        return count($this->requiredLessonIds());
    }

    /** @return Collection<int, int> */
    public function requiredLessonIds(): Collection
    {
        $compositions = $this->moduleCompositions()
            ->where('is_required', true)
            ->get();

        if ($compositions->isEmpty()) {
            return $this->lessons()->where('is_required', true)->pluck('id');
        }

        return $compositions
            ->pluck('lesson_id')
            ->unique()
            ->values();
    }

    /** @return Collection<int, int> */
    public function allLessonIds(): Collection
    {
        $compositions = $this->moduleCompositions()
            ->get();

        if ($compositions->isEmpty()) {
            return $this->lessons()->pluck('id');
        }

        return $compositions
            ->pluck('lesson_id')
            ->unique()
            ->values();
    }

    public function estimatedMinutes(): int
    {
        $seconds = (int) Video::query()
            ->whereIn('lesson_id', $this->allLessonIds())
            ->where('is_current', true)
            ->sum('duration_seconds');

        return (int) ceil($seconds / 60);
    }
}
