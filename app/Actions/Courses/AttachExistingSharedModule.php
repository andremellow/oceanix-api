<?php

namespace App\Actions\Courses;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Enums\ModuleVersionStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\ModuleVersion;
use App\Services\CourseEditor\EditorRevision;
use App\Services\SharedContent\SharedContentCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class AttachExistingSharedModule
{
    public function __construct(
        private readonly SharedContentCatalog $catalog,
        private readonly PrepareSharedCourseEditor $prepareEditor,
        private readonly EditorRevision $revisions,
    ) {}

    public function handle(CourseVersion $version, int $moduleVersionId, Account $actor, string $expectedRevision): CourseVersion
    {
        return DB::transaction(function () use ($version, $moduleVersionId, $actor, $expectedRevision): CourseVersion {
            $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            $course = Course::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($version->course_id);
            $locked = CourseVersion::query()->lockForUpdate()->where('course_id', $course->id)->findOrFail($version->id);
            if ($authorized === null || ! $course->is_shared || $course->company_id !== null || $course->status === CourseStatus::Archived
                || $locked->status !== CourseVersionStatus::Draft || $locked->publication_kind !== 'manual') {
                throw new LogicException('Only active platform administrators may attach modules to shared drafts.');
            }

            $rows = $locked->moduleCompositions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($this->revisions->forSharedCourse($course, $locked, $rows), $expectedRevision)) {
                throw ValidationException::withMessages(['revision' => __('This course changed elsewhere. Reload the page before trying again.')]);
            }
            if ($rows->contains('lesson_id', $moduleVersionId)) {
                throw ValidationException::withMessages(['modules' => __('This module is already attached to the draft.')]);
            }

            $source = $this->catalog->availableModules()->firstWhere('id', $moduleVersionId);
            if (! $source instanceof ModuleVersion || $source->status !== ModuleVersionStatus::Published || $source->lineage_archived_at !== null) {
                throw ValidationException::withMessages(['modules' => __('One or more selected modules are unavailable.')]);
            }
            $attachedLineages = ModuleVersion::query()->withoutGlobalScopes()->whereKey($rows->pluck('lesson_id'))->pluck('lineage_uuid');
            if ($attachedLineages->contains($source->lineage_uuid)) {
                throw ValidationException::withMessages(['modules' => __('This module is already attached to the draft.')]);
            }

            CourseVersionModule::query()->create([
                'course_version_id' => $locked->id,
                'module_version_id' => $source->id,
                'position' => ((int) $rows->max('position')) + 1,
                'is_required' => true,
            ]);

            return $this->prepareEditor->handle($course, $authorized, $this->prepareEditor->revision($course, $locked));
        }, 3);
    }
}
