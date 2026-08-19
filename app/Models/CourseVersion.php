<?php

namespace App\Models;

use App\Enums\CourseVersionStatus;
use Database\Factories\CourseVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'course_id', 'version_number', 'status', 'title', 'description',
    'completion_rule', 'published_at', 'published_by',
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
        return $this->lessons()->where('is_required', true)->count();
    }

    public function estimatedMinutes(): int
    {
        $seconds = (int) $this->lessons()
            ->join('videos', 'videos.lesson_id', '=', 'lessons.id')
            ->sum('videos.duration_seconds');

        return (int) ceil($seconds / 60);
    }
}
