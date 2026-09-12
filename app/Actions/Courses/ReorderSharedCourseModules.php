<?php

namespace App\Actions\Courses;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Services\CourseEditor\EditorRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ReorderSharedCourseModules
{
    public function __construct(private readonly EditorRevision $revisions) {}

    /** @param list<int> $orderedModuleVersionIds */
    public function handle(CourseVersion $version, Account $actor, array $orderedModuleVersionIds, string $expectedRevision): void
    {
        $ids = array_values(array_map('intval', $orderedModuleVersionIds));
        if ($ids === [] || count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['order' => __('The saved order is stale. Reload the draft and try again.')]);
        }

        DB::transaction(function () use ($version, $actor, $ids, $expectedRevision): void {
            $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
            $courseId = CourseVersion::query()->whereKey($version->id)->value('course_id');
            $course = Course::query()->withoutGlobalScopes()->lockForUpdate()->whereKey($courseId)->whereNull('company_id')->where('is_shared', true)->where('status', '!=', CourseStatus::Archived->value)->firstOrFail();
            $locked = CourseVersion::query()->lockForUpdate()->whereKey($version->id)->where('course_id', $course->id)->where('status', CourseVersionStatus::Draft->value)->where('publication_kind', 'manual')->firstOrFail();
            if ($authorized === null) {
                throw new LogicException('Only an active platform administrator can reorder shared content.');
            }
            $rows = CourseVersionModule::query()->where('course_version_id', $locked->id)->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($this->revisions->forSharedCourse($course, $locked, $rows), $expectedRevision)
                || $rows->pluck('lesson_id')->sort()->values()->all() !== collect($ids)->sort()->values()->all()) {
                throw ValidationException::withMessages(['order' => __('The saved order is stale. Reload the draft and try again.')]);
            }
            foreach ($ids as $index => $moduleId) {
                $rows->firstWhere('lesson_id', $moduleId)->update(['position' => 1000000 + $index]);
            }
            foreach ($ids as $index => $moduleId) {
                $rows->firstWhere('lesson_id', $moduleId)->update(['position' => $index + 1]);
            }
        }, 3);
    }
}
