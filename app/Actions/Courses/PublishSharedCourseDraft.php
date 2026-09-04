<?php

namespace App\Actions\Courses;

use App\Actions\Modules\PublishModuleVersion;
use App\Enums\ModuleVersionStatus;
use App\Exceptions\CoursePublicationException;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Services\Courses\CourseVersionValidator;
use App\Services\Modules\ModuleLineageLock;
use App\Services\Modules\ModuleVersionValidator;
use Illuminate\Support\Facades\DB;

class PublishSharedCourseDraft
{
    public function __construct(
        private readonly ModuleVersionValidator $moduleValidator,
        private readonly CourseVersionValidator $courseValidator,
        private readonly PublishModuleVersion $publishModule,
        private readonly PublishCourseVersion $publishCourse,
        private readonly ModuleLineageLock $lineageLock,
    ) {}

    public function handle(CourseVersion $version, Account $actor, bool $restartInProgress = false): CourseVersion
    {
        return DB::transaction(function () use ($version, $actor, $restartInProgress): CourseVersion {
            $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            $courseId = CourseVersion::query()->whereKey($version->id)->firstOrFail(['course_id'])->course_id;
            $course = Course::query()->lockForUpdate()->findOrFail($courseId);
            $version = CourseVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($authorized === null || (int) $version->course_id !== (int) $course->id || ! $course->is_shared || $course->company_id !== null || $version->publication_kind !== 'manual') {
                throw new \LogicException('Only an active platform administrator can publish a platform-owned shared course.');
            }

            $compositions = $version->moduleCompositions()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $modules = $this->lineageLock->versions($compositions->pluck('lesson_id'))->whereIn('id', $compositions->pluck('lesson_id'))->keyBy('id');
            if ($modules->count() !== $compositions->count() || $modules->contains(fn ($module): bool => $module->lineage_archived_at !== null || $module->getRawOriginal('status') === ModuleVersionStatus::Archived->value)) {
                throw new CoursePublicationException([__('One or more modules are unavailable.')]);
            }
            $compositions->each(fn ($composition) => $composition->setRelation('moduleVersion', $modules->get($composition->lesson_id)));
            $problems = $compositions
                ->map->moduleVersion
                ->filter(fn ($module) => $module?->status === ModuleVersionStatus::Draft)
                ->flatMap(fn ($module): array => $this->moduleValidator->problems($module))
                ->values()
                ->all();
            $problems = [...$problems, ...$this->courseValidator->problemsBeforeSharedModulePublication($version->fresh())];
            if ($problems !== []) {
                throw new CoursePublicationException(array_values(array_unique($problems)));
            }

            foreach ($compositions as $composition) {
                if ($composition->moduleVersion->status === ModuleVersionStatus::Draft) {
                    $this->publishModule->handle($composition->moduleVersion, $authorized, $restartInProgress);
                }
            }

            return $this->publishCourse->handle($version->fresh(), $authorized, $restartInProgress, 'manual');
        }, 3);
    }
}
