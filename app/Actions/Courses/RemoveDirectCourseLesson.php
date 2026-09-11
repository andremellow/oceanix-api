<?php

namespace App\Actions\Courses;

use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RemoveDirectCourseLesson
{
    public function handle(CourseVersion $version, User $actor, int $lessonId): void
    {
        DB::transaction(function () use ($version, $actor, $lessonId): void {
            $version = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);
            Gate::forUser($actor)->authorize('updateVersion', $version);
            $lesson = $version->lessons()->lockForUpdate()->findOrFail($lessonId);
            $lesson->delete();
            $version->lessons()->orderBy('position')->lockForUpdate()->get()->each(function (Lesson $lesson, int $index): void {
                $lesson->update(['position' => $index + 1]);
                $lesson->courseVersion->moduleCompositions()->where('lesson_id', $lesson->id)->update(['position' => $index + 1]);
            });
        });
    }
}
