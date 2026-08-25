<?php

namespace App\Services\Platform;

use App\Models\Account;
use App\Models\Company;

class PlatformOverview
{
    /** @return array{companies: int, platform_administrators: int} */
    public function metrics(): array
    {
        return [
            'companies' => Company::query()->count(),
            'platform_administrators' => Account::query()
                ->where('is_platform_admin', true)
                ->where('status', 'active')
                ->count(),
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

    public function administrators()
    {
        return Account::query()
            ->where('is_platform_admin', true)
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderBy('name')
            ->orderBy('email')
            ->get();
    }
}
