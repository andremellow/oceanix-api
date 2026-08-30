<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class PlatformTaskUser extends User
{
    protected $table = 'users';

    /**
     * Platform task users are resolved outside tenant context, so the tenant scope inherited
     * from User must not hide them. The platform-administrator scope below is the boundary.
     */
    public static function bootBelongsToCompany(): void {}

    protected static function booted(): void
    {
        static::addGlobalScope('platform-administrator', fn (Builder $query) => $query
            ->whereHas('account', fn (Builder $accounts) => $accounts
                ->where('is_platform_admin', true)
                ->where('status', 'active'))
            // One account may have a tenant user in several companies. Tasks are
            // global, so expose that person once using their first user record.
            ->whereNotExists(fn ($duplicates) => $duplicates
                ->selectRaw('1')
                ->from('users as earlier_platform_users')
                ->whereColumn('earlier_platform_users.account_id', 'users.account_id')
                ->whereColumn('earlier_platform_users.id', '<', 'users.id')));
    }
}
