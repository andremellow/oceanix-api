<?php

namespace App\Services\SharedContent;

use App\Enums\AssignmentStatus;
use App\Enums\CourseStatus;
use App\Models\Company;
use App\Models\CompanyCourse;
use App\Models\Course;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\UserTrainingAssignment;
use Illuminate\Database\Eloquent\Collection;

class SharedContentCatalog
{
    /** @return array{not_started: int, in_progress: int} */
    public function coursePublicationImpact(Course $course): array
    {
        $versionId = $course->current_published_version_id ?? $course->draftVersion()?->id;

        if ($versionId === null) {
            return ['not_started' => 0, 'in_progress' => 0];
        }

        $assignments = UserTrainingAssignment::query()->withoutGlobalScopes()
            ->where('course_version_id', $versionId)
            ->whereIn('status', array_column(AssignmentStatus::open(), 'value'));

        $startedIds = (clone $assignments)
            ->whereIn('status', [AssignmentStatus::InProgress->value, AssignmentStatus::Failed->value])
            ->pluck('id');

        return [
            'not_started' => (clone $assignments)->whereNotIn('id', $startedIds)->count(),
            'in_progress' => $startedIds->count(),
        ];
    }

    /** @return Collection<int, Course> */
    public function platformCourses(?string $search = null): Collection
    {
        return Course::query()->withoutGlobalScopes()
            ->whereNull('company_id')->where('is_shared', true)
            ->when(filled($search), fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'like', '%'.trim((string) $search).'%')
                ->orWhere('code', 'like', '%'.trim((string) $search).'%')))
            ->withCount(['versions', 'companyAssociations' => fn ($query) => $query->active()])
            ->with('currentPublishedVersion')
            ->orderBy('title')->get();
    }

    /** @return Collection<int, Module> */
    public function platformModules(?string $search = null): Collection
    {
        return Module::query()->withoutGlobalScopes()
            ->whereNull('company_id')->where('is_shared', true)
            ->when(filled($search), fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'like', '%'.trim((string) $search).'%')
                ->orWhere('code', 'like', '%'.trim((string) $search).'%')))
            ->withCount('versions')->with('currentPublishedVersion')
            ->orderBy('title')->get();
    }

    /** @return Collection<int, Course> */
    public function availableCourses(?string $search = null): Collection
    {
        return $this->platformCourses($search)
            ->filter(fn (Course $course): bool => $course->status === CourseStatus::Active && $course->current_published_version_id !== null)
            ->values();
    }

    /** @return Collection<int, Course> */
    public function eligibleCourses(Company $company, ?string $search = null): Collection
    {
        return $this->availableCourses($search)
            ->reject(fn (Course $course): bool => CompanyCourse::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)->where('course_id', $course->id)->active()->exists())
            ->values();
    }

    public function associationFor(Company $company, Course $course): ?CompanyCourse
    {
        return CompanyCourse::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)->where('course_id', $course->id)->first();
    }

    /** @return Collection<int, Module> */
    public function availableModules(?string $search = null): Collection
    {
        return ModuleVersion::query()->withoutGlobalScopes()
            ->shared()
            ->where('status', 'published')
            ->when(filled($search), fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'like', '%'.trim((string) $search).'%')
                ->orWhere('code', 'like', '%'.trim((string) $search).'%')))
            ->orderBy('title')
            ->get();
    }
}
