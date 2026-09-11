<?php

namespace App\Actions\Courses;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Services\Audit\AuditLogger;
use App\Services\CourseEditor\EditorRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class RemoveSharedCourseModule
{
    public function __construct(private readonly AuditLogger $audit, private readonly EditorRevision $revisions) {}

    public function handle(CourseVersion $version, int $compositionId, Account $actor, string $reason, string $expectedRevision): CourseVersion
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['removalReason' => __('A reason is required.')]);
        }

        return DB::transaction(function () use ($version, $compositionId, $actor, $reason, $expectedRevision): CourseVersion {
            $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            $courseId = CourseVersion::query()->whereKey($version->id)->firstOrFail(['course_id'])->course_id;
            $course = Course::query()->lockForUpdate()->findOrFail($courseId);
            $locked = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($authorized === null || ! $course->is_shared || $course->company_id !== null || $course->status === CourseStatus::Archived || $locked->status !== CourseVersionStatus::Draft || $locked->publication_kind !== 'manual') {
                throw new LogicException('Only active platform administrators may remove modules from shared drafts.');
            }
            if ((int) $locked->course_id !== (int) $course->id) {
                throw new LogicException('The draft changed courses while it was being locked.');
            }

            $compositions = CourseVersionModule::query()->where('course_version_id', $locked->id)->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($this->revisions->forSharedCourse($course, $locked, $compositions), $expectedRevision)) {
                throw ValidationException::withMessages(['removal' => __('This course changed elsewhere. Reload the page before trying again.')]);
            }
            $composition = $compositions->firstWhere('id', $compositionId);
            if ($composition === null) {
                throw ValidationException::withMessages(['removal' => __('This module is no longer attached to the draft.')]);
            }

            $before = ['course_version_id' => $locked->id, 'module_version_id' => $composition->lesson_id, 'position' => $composition->position, 'is_required' => (bool) $composition->is_required];
            $composition->delete();
            foreach (CourseVersionModule::query()->where('course_version_id', $locked->id)->orderBy('position')->orderBy('id')->lockForUpdate()->get()->values() as $index => $remaining) {
                $remaining->update(['position' => $index + 1]);
            }
            $this->audit->log('shared_course.module_removed', $locked, before: $before, after: ['reason' => $reason], platformActor: $authorized);

            return $locked->refresh();
        });
    }

    public function revision(CourseVersion $version, $compositions = null): string
    {
        $compositions ??= $version->moduleCompositions()->get();
        $course = $version->course()->withoutGlobalScopes()->firstOrFail();

        return $this->revisions->forSharedCourse($course, $version, $compositions);
    }
}
