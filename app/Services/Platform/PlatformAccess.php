<?php

namespace App\Services\Platform;

use App\Enums\PlatformPermission;
use App\Models\Account;

class PlatformAccess extends \Oceanix\Platform\Services\Platform\PlatformAccess
{
    public function account(): ?Account
    {
        // Existing tenant logins may also carry an explicitly authorized global account.
        // Query it on every request/action so loaded relations cannot retain revoked access.
        $accountId = auth()->user()?->account_id;
        $account = $accountId === null ? null : Account::query()
            ->whereKey($accountId)->where('is_platform_admin', true)->where('status', 'active')->first();

        return $account ?? parent::account();
    }

    /** Platform administrators retain the existing explicit all-permissions bypass. */
    public function authorizePermission(PlatformPermission $permission): Account
    {
        return $this->authorize();
    }
}
