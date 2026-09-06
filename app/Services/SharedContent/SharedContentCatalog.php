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
        $versionId = $course->current_published_version_id ?? $course->manualDraftVersion()?->id;

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
            ->whereNull('lineage_archived_at')
            ->whereIn('status', ['published', 'draft'])
            // Prefer the latest publication; use the active draft only before first publication.
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('lessons as newer')
                ->whereColumn('newer.lineage_uuid', 'lessons.lineage_uuid')
                ->whereNull('newer.company_id')->where('newer.is_shared', true)
                ->whereNull('newer.lineage_archived_at')->whereIn('newer.status', ['published', 'draft'])
                ->where(fn ($preferred) => $preferred
                    ->where(fn ($published) => $published->where('newer.status', 'published')->where('lessons.status', 'draft'))
                    ->orWhere(fn ($latest) => $latest->whereColumn('newer.status', 'lessons.status')
                        ->whereColumn('newer.version_number', '>', 'lessons.version_number'))))
            ->when(filled($search), fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'like', '%'.trim((string) $search).'%')
                ->orWhere('code', 'like', '%'.trim((string) $search).'%')))
            ->withExists(['versions as has_active_draft' => fn ($query) => $query
                ->where('status', 'draft')->whereNull('lineage_archived_at')])
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
            ->whereNull('lineage_archived_at')
            ->where('status', 'published')
            ->when(filled($search), fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'like', '%'.trim((string) $search).'%')
                ->orWhere('code', 'like', '%'.trim((string) $search).'%')))
            ->orderBy('title')
            ->get();
    }
}
