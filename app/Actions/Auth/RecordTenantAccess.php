<?php

namespace App\Actions\Auth;

use App\Enums\UserStatus;
use App\Exceptions\SocialLoginProviderException;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordTenantAccess
{
    public function handle(User $person): User
    {
        return DB::transaction(function () use ($person) {
            $locked = User::withoutGlobalScope('company')->where('company_id', $person->company_id)->whereKey($person->id)->lockForUpdate()->firstOrFail();
            $account = Account::query()->whereKey($locked->account_id)->lockForUpdate()->first();
            if (! $locked->status->canAccessTenant() || ! $account || $account->status !== 'active' || $locked->account_id !== $person->account_id) {
                throw SocialLoginProviderException::accountInactive();
            }
            $time = now();
            $locked->forceFill(['first_access_at' => $locked->first_access_at ?? $time, 'last_access_at' => $locked->last_access_at && $locked->last_access_at->greaterThan($time) ? $locked->last_access_at : $time, 'status' => UserStatus::Active])->save();

            return $locked;
        });
    }
}
