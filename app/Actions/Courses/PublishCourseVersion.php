<?php

namespace App\Actions\Courses;

use App\Actions\Assignments\ReplaceAssignmentsForPublication;
use App\Actions\Assignments\ReplaceOpenAssignmentsForCourseVersion;
use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Exceptions\CoursePublicationException;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Courses\CourseVersionValidator;
use App\Services\Modules\ModuleLineageLock;
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
        private readonly ModuleLineageLock $lineageLock,
    ) {}

    public function handle(CourseVersion $version, int|User|Account $publishedBy, bool $replaceOpenAssignments = false, string $expectedPublicationKind = 'manual'): CourseVersion
    {
        $account = $publishedBy instanceof Account ? $publishedBy : null;
        $userId = $publishedBy instanceof User ? $publishedBy->id : (is_int($publishedBy) ? $publishedBy : null);

        return DB::transaction(function () use ($version, $userId, $account, $replaceOpenAssignments, $expectedPublicationKind): CourseVersion {
            $courseId = CourseVersion::query()->whereKey($version->id)->firstOrFail(['course_id'])->course_id;
            $course = Course::query()->lockForUpdate()->findOrFail($courseId);
            $version = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ((int) $version->course_id !== (int) $course->id) {
                throw new \LogicException('The version changed courses while it was being locked.');
            }
            if ($course->is_shared && ($account === null || ! $account->is_platform_admin || $account->status !== 'active')) {
                throw new \LogicException('An active platform administrator account is required to publish shared content.');
            }
            if ($course->status === CourseStatus::Archived) {
                throw new \LogicException('Archived courses cannot publish new versions.');
            }
            if (! $version->isEditable()) {
                throw CoursePublicationException::notEditable();
            }
            if ($version->publication_kind !== $expectedPublicationKind) {
                throw new \LogicException('This course version cannot be published through this lifecycle.');
            }

            $previous = $course->currentPublishedVersion()->lockForUpdate()->first();
            $compositions = $version->moduleCompositions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $modules = $this->lineageLock->versions($compositions->pluck('lesson_id'))->whereIn('id', $compositions->pluck('lesson_id'));
            if ($modules->count() !== $compositions->count() || $modules->contains(fn ($module): bool => $module->lineage_archived_at !== null || $module->getRawOriginal('status') === 'archived')) {
                throw new CoursePublicationException([__('One or more modules are unavailable.')]);
            }
            $problems = $this->validator->problems($version->fresh());
            if ($problems !== []) {
                throw new CoursePublicationException($problems);
            }

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
        }, 3);
    }
}
