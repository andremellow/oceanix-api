<?php

namespace App\Actions\Courses;

use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Courses\CourseVersionComposition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AddDirectCourseLesson
{
    public function __construct(private readonly CourseVersionComposition $composition) {}

    public function handle(CourseVersion $version, User $actor): Lesson
    {
        return DB::transaction(function () use ($version, $actor): Lesson {
            $version = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);
            Gate::forUser($actor)->authorize('updateVersion', $version);
            $state = $this->composition->inspect($version);
            if (in_array($state['mode'], [CourseVersionComposition::Modules, CourseVersionComposition::Mixed], true)) {
                throw ValidationException::withMessages(['composition' => __('ui.direct_lesson_conflict')]);
            }

            return Lesson::query()->create([
                'course_version_id' => $version->id,
                'title' => __('New lesson'),
                'position' => ((int) $version->lessons()->max('position')) + 1,
            ]);
        });
    }
}
