<?php

namespace App\Actions\Courses;

use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\User;
use App\Services\Modules\ModuleLineageLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateCourseModuleComposition
{
    public function __construct(private readonly ModuleLineageLock $lineageLock) {}

    /** @param list<int> $moduleVersionIds */
    public function handle(CourseVersion $version, array $moduleVersionIds, ?User $actor = null): CourseVersion
    {
        $ids = array_values(array_map('intval', $moduleVersionIds));
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['modules' => __('A module can only be included once.')]);
        }

        return DB::transaction(function () use ($version, $ids, $actor): CourseVersion {
            $courseId = CourseVersion::query()->whereKey($version->id)->firstOrFail(['course_id'])->course_id;
            $course = Course::query()->lockForUpdate()->findOrFail($courseId);
            $version = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ((int) $version->course_id !== (int) $course->id || ! $version->isEditable()) {
                throw new \LogicException('Only a draft course version can be composed.');
            }
            if ($actor !== null) {
                Gate::forUser($actor)->authorize('updateVersion', $version);
            }
            if ($course->is_shared || $course->company_id === null) {
                throw new AuthorizationException('Company course required.');
            }

            $version->moduleCompositions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $moduleVersions = $this->lineageLock->versions($ids)->whereIn('id', $ids)->keyBy('id');
            if ($moduleVersions->count() !== count($ids)) {
                throw ValidationException::withMessages(['modules' => __('One or more selected modules are unavailable.')]);
            }
            foreach ($ids as $id) {
                $moduleVersion = $moduleVersions[$id];
                $eligibleOwner = ($moduleVersion->is_shared && $moduleVersion->company_id === null)
                    || (! $moduleVersion->is_shared && (int) $moduleVersion->company_id === (int) $course->company_id);
                if (! $eligibleOwner || $moduleVersion->lineage_archived_at !== null || $moduleVersion->getRawOriginal('status') !== 'published') {
                    throw new \LogicException(__('One or more selected modules are unavailable.'));
                }
                if ($actor !== null && Gate::forUser($actor)->denies('use', $moduleVersion)) {
                    throw new AuthorizationException;
                }
            }

            $version->moduleCompositions()->delete();

            foreach ($ids as $index => $moduleVersionId) {
                CourseVersionModule::query()->create([
                    'course_version_id' => $version->id,
                    'module_version_id' => $moduleVersionId,
                    'position' => $index + 1,
                    'is_required' => true,
                ]);
            }

            return $version->refresh();
        });
    }
}
