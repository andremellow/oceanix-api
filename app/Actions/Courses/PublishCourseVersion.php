<?php

namespace App\Actions\Courses;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Exceptions\CoursePublicationException;
use App\Models\CourseVersion;
use App\Services\Audit\AuditLogger;
use App\Services\Courses\CourseVersionValidator;
use Illuminate\Support\Facades\DB;

/**
 * Publishing freezes a version forever and makes it the edition new assignments will
 * point at. The previously published version is retired, never deleted — assignments and
 * certificates issued against it keep resolving.
 */
class PublishCourseVersion
{
    public function __construct(
        private readonly CourseVersionValidator $validator,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(CourseVersion $version, int $publishedBy): CourseVersion
    {
        if (! $version->isEditable()) {
            throw CoursePublicationException::notEditable();
        }

        $problems = $this->validator->problems($version);

        if ($problems !== []) {
            throw new CoursePublicationException($problems);
        }

        return DB::transaction(function () use ($version, $publishedBy): CourseVersion {
            $course = $version->course;
            $previous = $course->currentPublishedVersion;

            $version->update([
                'status' => CourseVersionStatus::Published,
                'published_at' => now(),
                'published_by' => $publishedBy,
            ]);

            $previous?->update(['status' => CourseVersionStatus::Retired]);

            $course->update([
                'current_published_version_id' => $version->id,
                'status' => CourseStatus::Active,
            ]);

            $this->audit->log(
                'course_version.published',
                $version,
                before: ['current_published_version' => $previous?->version_number],
                after: ['current_published_version' => $version->version_number],
            );

            return $version->refresh();
        });
    }
}
