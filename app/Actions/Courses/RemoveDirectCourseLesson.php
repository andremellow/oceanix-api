<?php

namespace App\Actions\Courses;

use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CourseEditor\EditorRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RemoveDirectCourseLesson
{
    public function __construct(private readonly EditorRevision $revisions) {}

    public function handle(CourseVersion $version, User $actor, int $lessonId, string $expectedRevision): void
    {
        $this->revisions->assertExpected($expectedRevision);

        DB::transaction(function () use ($version, $actor, $lessonId, $expectedRevision): void {
            $course = Course::query()->lockForUpdate()->findOrFail($version->course_id);
            $version = CourseVersion::query()->lockForUpdate()->where('course_id', $course->id)->findOrFail($version->id);
            Gate::forUser($actor)->authorize('updateVersion', $version);
            if (! $version->isEditable()) {
                throw new AuthorizationException;
            }
            $version->moduleCompositions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $lessons = $version->lessons()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($this->revisions->forCompanyCourse($course, $version), $expectedRevision)) {
                throw ValidationException::withMessages(['revision' => __('This draft changed in another session. Reload it before changing the structure.')]);
            }
            $lesson = $lessons->firstWhere('id', $lessonId) ?? abort(404);
            $lesson->delete();
            $lessons->where('id', '!=', $lessonId)->sortBy('position')->values()->each(function (Lesson $lesson, int $index) use ($version): void {
                if ((int) $lesson->position !== $index + 1) {
                    $lesson->update(['position' => $index + 1]);
                }
                $version->moduleCompositions()->where('lesson_id', $lesson->id)->update(['position' => $index + 1]);
            });
        });
    }
}
