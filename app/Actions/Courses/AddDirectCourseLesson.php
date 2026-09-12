<?php

namespace App\Actions\Courses;

use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CourseEditor\EditorRevision;
use App\Services\Courses\CourseVersionComposition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AddDirectCourseLesson
{
    public function __construct(private readonly CourseVersionComposition $composition, private readonly EditorRevision $revisions) {}

    public function handle(CourseVersion $version, User $actor, string $expectedRevision): Lesson
    {
        $this->revisions->assertExpected($expectedRevision);

        return DB::transaction(function () use ($version, $actor, $expectedRevision): Lesson {
            $course = Course::query()->lockForUpdate()->findOrFail($version->course_id);
            $version = CourseVersion::query()->lockForUpdate()->where('course_id', $course->id)->findOrFail($version->id);
            Gate::forUser($actor)->authorize('updateVersion', $version);
            if (! $version->isEditable()) {
                throw new AuthorizationException;
            }
            $version->moduleCompositions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $version->lessons()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($this->revisions->forCompanyCourse($course, $version), $expectedRevision)) {
                throw ValidationException::withMessages(['revision' => __('This draft changed in another session. Reload it before changing the structure.')]);
            }
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
