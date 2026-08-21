<?php

namespace App\Services\Platform;

use App\Models\Account;

class PlatformAccess
{
    public function account(): ?Account
    {
        $account = auth()->user()?->account;

        if ($account?->is_platform_admin && $account->status === 'active') {
            return $account;
        }

        $accountId = session('platform_account_id');

        return is_numeric($accountId)
            ? Account::query()->whereKey($accountId)->where('is_platform_admin', true)->where('status', 'active')->first()
            : null;
    }

    public function authorize(): Account
    {
        return $this->account() ?? abort(403);
    }
}
