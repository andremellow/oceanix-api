<?php

namespace App\Services\Courses;

use App\Models\Company;
use App\Models\Course;
use Illuminate\Database\Eloquent\Collection;

class CompanyCourseLibrary
{
    /** @return Collection<int, Course> */
    public function courses(Company $company): Collection
    {
        return $this->companyCourses($company)
            ->merge($this->sharedCourses($company))
            ->sortBy(fn (Course $course): string => mb_strtolower($course->title))
            ->values();
    }

    /** @return Collection<int, Course> */
    public function companyCourses(Company $company, ?string $search = null, ?string $status = null): Collection
    {
        return Course::query()->withoutGlobalScopes()->companyOwned($company)
            ->when(filled($search), fn ($query) => $this->search($query, (string) $search))
            ->when(filled($status), fn ($query) => $query->where('status', $status))
            ->with(['currentPublishedVersion'])->withCount('assignments')->orderBy('title')->get();
    }

    /** @return Collection<int, Course> */
    public function sharedCourses(Company $company, ?string $search = null): Collection
    {
        return Course::query()->withoutGlobalScopes()->shared()
            ->whereHas('companyAssociations', fn ($query) => $query
                ->withoutGlobalScopes()->where('company_id', $company->id)->active())
            ->when(filled($search), fn ($query) => $this->search($query, (string) $search))
            ->with(['currentPublishedVersion'])->withCount('assignments')->orderBy('title')->get();
    }

    private function search($query, string $search): void
    {
        $term = '%'.strtolower(trim($search)).'%';
        $query->where(fn ($query) => $query->whereRaw('lower(title) like ?', [$term])->orWhereRaw('lower(code) like ?', [$term]));
    }
}
