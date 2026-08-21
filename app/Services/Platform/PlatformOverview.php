<?php

namespace App\Services\Platform;

use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Course;
use App\Models\User;
use App\Models\UserTrainingAssignment;

class PlatformOverview
{
    /** @return array{companies: int, people: int, courses: int, assignments: int} */
    public function metrics(): array
    {
        return [
            'companies' => Company::query()->count(),
            'people' => User::withoutGlobalScope('company')->where('status', UserStatus::Active)->count(),
            'courses' => Course::withoutGlobalScope('company')->count(),
            'assignments' => UserTrainingAssignment::withoutGlobalScope('company')->open()->count(),
        ];
    }

    public function companies(?int $accountId = null)
    {
        return Company::query()
            ->withCount([
                'users as people_count' => fn ($query) => $query->withoutGlobalScope('company'),
            ])
            ->withExists([
                'users as account_linked' => fn ($query) => $query
                    ->withoutGlobalScope('company')
                    ->when($accountId !== null, fn ($scoped) => $scoped->where('account_id', $accountId))
                    ->when($accountId === null, fn ($scoped) => $scoped->whereRaw('1 = 0')),
            ])
            ->orderBy('name')
            ->get();
    }
}
