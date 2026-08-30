<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Cross-company projection used only by the platform task package.
 *
 * Task foreign keys still reference users, while this projection prevents the
 * platform board from offering ordinary company employees as assignees.
 */
class PlatformTaskUser extends Authenticatable
{
    protected $table = 'users';

    protected static function booted(): void
    {
        static::addGlobalScope('platform-administrator', fn (Builder $query) => $query
            ->whereHas('account', fn (Builder $accounts) => $accounts
                ->where('is_platform_admin', true)
                ->where('status', 'active')));
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
