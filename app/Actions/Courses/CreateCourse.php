<?php

namespace App\Actions\Courses;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Creates the permanent course identity together with its first draft version. */
class CreateCourse
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(string $code, string $title, ?string $description = null): Course
    {
        return DB::transaction(function () use ($code, $title, $description): Course {
            $course = Course::query()->create([
                'code' => strtoupper(trim($code)),
                'title' => trim($title),
                'description' => $description,
                'status' => CourseStatus::Draft,
            ]);

            CourseVersion::query()->create([
                'course_id' => $course->id,
                'version_number' => 1,
                'status' => CourseVersionStatus::Draft,
                'title' => $course->title,
                'description' => $course->description,
            ]);

            $this->audit->log('course.created', $course, after: [
                'code' => $course->code,
                'title' => $course->title,
            ]);

            return $course->refresh();
        });
    }
}
