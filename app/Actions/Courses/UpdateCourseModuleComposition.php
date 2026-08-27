<?php

namespace App\Actions\Courses;

use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\ModuleVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateCourseModuleComposition
{
    /** @param list<int> $moduleVersionIds */
    public function handle(CourseVersion $version, array $moduleVersionIds, ?User $actor = null): CourseVersion
    {
        if (! $version->isEditable()) {
            throw new \LogicException('Only a draft course version can be composed.');
        }

        if ($actor !== null) {
            Gate::forUser($actor)->authorize('updateVersion', $version);
        }

        if ($version->course->is_shared || $version->course->company_id === null) {
            throw new AuthorizationException('Company course required.');
        }

        $ids = array_values(array_map('intval', $moduleVersionIds));
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['modules' => __('A module can only be included once.')]);
        }

        $moduleVersions = ModuleVersion::query()->whereKey($ids)->get()->keyBy('id');
        if ($moduleVersions->count() !== count($ids)) {
            throw ValidationException::withMessages(['modules' => __('One or more selected modules are unavailable.')]);
        }

        foreach ($ids as $id) {
            $moduleVersion = $moduleVersions[$id];
            $eligibleOwner = ($moduleVersion->is_shared && $moduleVersion->company_id === null)
                || (! $moduleVersion->is_shared && (int) $moduleVersion->company_id === (int) $version->course->company_id);

            if (! $eligibleOwner
                || $moduleVersion->getRawOriginal('status') !== 'published') {
                throw new \LogicException(__('One or more selected modules are unavailable.'));
            }

            if ($actor !== null && Gate::forUser($actor)->denies('use', $moduleVersion)) {
                throw new AuthorizationException;
            }
        }

        return DB::transaction(function () use ($version, $ids): CourseVersion {
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
