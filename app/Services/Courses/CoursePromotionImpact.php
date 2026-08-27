<?php

namespace App\Services\Courses;

use App\Enums\CourseStatus;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersionModule;
use App\Models\Module;
use DomainException;
use Illuminate\Support\Collection;

class CoursePromotionImpact
{
    /**
     * @return array{course: Course, source_company: Company, modules: Collection<int, Module>, affected_courses: Collection<int, Course>, token: string}
     */
    public function preview(Course $course, Company $sourceCompany, bool $lock = false): array
    {
        $courseQuery = Course::query()->withoutGlobalScopes()->whereKey($course->id);
        $course = ($lock ? $courseQuery->lockForUpdate() : $courseQuery)->firstOrFail();

        if ($course->is_shared || (int) $course->company_id !== (int) $sourceCompany->id
            || in_array($course->status, [CourseStatus::Retired, CourseStatus::Archived], true)) {
            throw new DomainException('This company course is not eligible for promotion.');
        }

        if (Course::query()->withoutGlobalScopes()->shared()->where('code', $course->code)->exists()) {
            throw new DomainException('A shared course already uses this code.');
        }

        $currentVersionId = $course->current_published_version_id
            ?? $course->versions()->orderByDesc('version_number')->value('id');
        $moduleIds = CourseVersionModule::query()
            ->where('course_version_id', $currentVersionId)
            ->distinct()
            ->orderBy('lesson_id')
            ->pluck('lesson_id');

        $moduleQuery = Module::query()->withoutGlobalScopes()->whereIn('id', $moduleIds)->orderBy('id');
        $modules = ($lock ? $moduleQuery->lockForUpdate() : $moduleQuery)->get();

        if ($modules->contains(fn (Module $module): bool => ! $module->is_shared
            && (int) $module->company_id !== (int) $sourceCompany->id)) {
            throw new DomainException('The course contains content owned by another company.');
        }

        $conflictingModuleCode = Module::query()->withoutGlobalScopes()->shared()
            ->whereIn('code', $modules->where('is_shared', false)->pluck('code'))->exists();

        if ($conflictingModuleCode) {
            throw new DomainException('A shared module already uses a code required by this course.');
        }

        $transferredLineages = $modules->where('is_shared', false)->pluck('lineage_uuid');
        $transferredModuleIds = Module::query()->withoutGlobalScopes()
            ->whereIn('lineage_uuid', $transferredLineages)
            ->pluck('id');
        $affectedCourseIds = CourseVersionModule::query()
            ->whereIn('lesson_id', $transferredModuleIds)
            ->whereIn('course_version_id', function ($query) use ($course): void {
                $query->select('id')->from('course_versions')->where('course_id', '!=', $course->id);
            })
            ->join('course_versions', 'course_versions.id', '=', 'course_version_lessons.course_version_id')
            ->distinct()->orderBy('course_versions.course_id')->pluck('course_versions.course_id');

        $affectedQuery = Course::query()->withoutGlobalScopes()->whereIn('id', $affectedCourseIds)->orderBy('id');
        $affectedCourses = ($lock ? $affectedQuery->lockForUpdate() : $affectedQuery)->get();

        if ($affectedCourses->contains(fn (Course $affected): bool => $affected->is_shared
            || (int) $affected->company_id !== (int) $sourceCompany->id)) {
            throw new DomainException('A module is reused outside the source company and cannot be transferred safely.');
        }

        $snapshot = [
            'course' => [$course->id, $course->company_id, $course->is_shared, $course->status->value, $course->code, $course->title, $course->current_published_version_id, $course->updated_at?->format('U.u')],
            'modules' => $modules->map(fn (Module $module): array => [
                $module->id, $module->company_id, $module->is_shared, (string) $module->status,
                $module->code, $module->title, $module->lineage_uuid, $module->version_number,
                $module->updated_at?->format('U.u'),
            ])->values()->all(),
            'affected_courses' => $affectedCourses->map(fn (Course $affected): array => [
                $affected->id, $affected->company_id, $affected->current_published_version_id, $affected->updated_at?->format('U.u'),
            ])->values()->all(),
            'source_composition' => CourseVersionModule::query()
                ->whereIn('course_version_id', $course->versions()->select('id'))
                ->orderBy('course_version_id')->orderBy('position')
                ->get(['course_version_id', 'lesson_id', 'position', 'is_required'])->toArray(),
        ];

        return [
            'course' => $course,
            'source_company' => $sourceCompany,
            'modules' => $modules,
            'affected_courses' => $affectedCourses,
            'token' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
        ];
    }
}
