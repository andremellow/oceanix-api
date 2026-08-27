<?php

namespace App\Actions\Courses;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Creates the permanent course identity together with its first draft version. */
class CreateCourse
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(string $code, string $title, ?string $description = null, ?Account $platformActor = null): Course
    {
        if ($platformActor !== null && ! $platformActor->is_platform_admin) {
            throw new LogicException('Only a platform administrator can create shared content.');
        }

        return DB::transaction(function () use ($code, $title, $description, $platformActor): Course {
            $course = Course::query()->create([
                'company_id' => $platformActor === null ? app(TenantContext::class)->id() : null,
                'is_shared' => $platformActor !== null,
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
                'published_by_account_id' => $platformActor?->id,
            ]);

            if ($platformActor === null) {
                $this->audit->log('course.created', $course, after: [
                    'code' => $course->code,
                    'title' => $course->title,
                ]);
            }

            return $course->refresh();
        });
    }
}
