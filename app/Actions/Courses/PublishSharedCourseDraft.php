<?php

namespace App\Actions\Courses;

use App\Actions\Modules\PublishModuleVersion;
use App\Enums\ModuleVersionStatus;
use App\Exceptions\CoursePublicationException;
use App\Models\Account;
use App\Models\CourseVersion;
use App\Services\Courses\CourseVersionValidator;
use App\Services\Modules\ModuleVersionValidator;
use Illuminate\Support\Facades\DB;

class PublishSharedCourseDraft
{
    public function __construct(
        private readonly ModuleVersionValidator $moduleValidator,
        private readonly CourseVersionValidator $courseValidator,
        private readonly PublishModuleVersion $publishModule,
        private readonly PublishCourseVersion $publishCourse,
    ) {}

    public function handle(CourseVersion $version, Account $actor, bool $restartInProgress = false): CourseVersion
    {
        $compositions = $version->moduleCompositions()->with('moduleVersion')->get();
        $problems = $compositions
            ->map->moduleVersion
            ->filter(fn ($module) => $module?->status === ModuleVersionStatus::Draft)
            ->flatMap(fn ($module): array => $this->moduleValidator->problems($module))
            ->values()
            ->all();
        $problems = [...$problems, ...$this->courseValidator->problemsBeforeSharedModulePublication($version)];

        if ($problems !== []) {
            throw new CoursePublicationException(array_values(array_unique($problems)));
        }

        return DB::transaction(function () use ($version, $actor, $restartInProgress, $compositions): CourseVersion {
            foreach ($compositions as $composition) {
                if ($composition->moduleVersion->status === ModuleVersionStatus::Draft) {
                    $this->publishModule->handle($composition->moduleVersion, $actor, $restartInProgress);
                }
            }

            return $this->publishCourse->handle($version->fresh(), $actor, $restartInProgress);
        });
    }
}
