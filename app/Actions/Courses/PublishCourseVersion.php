<?php

namespace App\Actions\Courses;

use App\Actions\Assignments\ReplaceAssignmentsForPublication;
use App\Actions\Assignments\ReplaceOpenAssignmentsForCourseVersion;
use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Exceptions\CoursePublicationException;
use App\Models\Account;
use App\Models\CourseVersion;
use App\Models\User;
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
        private readonly ReplaceOpenAssignmentsForCourseVersion $replaceAssignments,
        private readonly ReplaceAssignmentsForPublication $replacePublicationAssignments,
    ) {}

    public function handle(CourseVersion $version, int|User|Account $publishedBy, bool $replaceOpenAssignments = false): CourseVersion
    {
        $account = $publishedBy instanceof Account ? $publishedBy : null;
        $userId = $publishedBy instanceof User ? $publishedBy->id : (is_int($publishedBy) ? $publishedBy : null);

        if ($version->course->is_shared && ($account === null || ! $account->is_platform_admin)) {
            throw new \LogicException('A platform administrator account is required to publish shared content.');
        }

        if ($version->course->status === CourseStatus::Archived) {
            throw new \LogicException('Archived courses cannot publish new versions.');
        }

        if (! $version->isEditable()) {
            throw CoursePublicationException::notEditable();
        }

        $problems = $this->validator->problems($version);

        if ($problems !== []) {
            throw new CoursePublicationException($problems);
        }

        return DB::transaction(function () use ($version, $userId, $account, $replaceOpenAssignments): CourseVersion {
            $course = $version->course;
            $previous = $course->currentPublishedVersion;

            $version->update([
                'status' => CourseVersionStatus::Published,
                'published_at' => now(),
                'published_by' => $userId,
                'published_by_account_id' => $account?->id,
            ]);

            $previous?->update(['status' => CourseVersionStatus::Retired]);

            $course->update([
                'current_published_version_id' => $version->id,
                'status' => CourseStatus::Active,
            ]);

            if ($previous !== null && $account !== null) {
                $this->replacePublicationAssignments->handle(
                    $previous,
                    $version,
                    $account,
                    restartInProgress: $replaceOpenAssignments,
                );
            } elseif ($replaceOpenAssignments) {
                $this->replaceAssignments->handle($version);
            }

            if ($account === null) {
                $this->audit->log(
                    'course_version.published',
                    $version,
                    before: ['current_published_version' => $previous?->version_number],
                    after: ['current_published_version' => $version->version_number],
                );
            }

            return $version->refresh();
        });
    }
}
