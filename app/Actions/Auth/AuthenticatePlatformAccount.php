<?php

namespace App\Actions\Auth;

use App\Data\SocialIdentity;
use App\Exceptions\SocialLoginProviderException;
use App\Models\Account;

class AuthenticatePlatformAccount
{
    public function handle(SocialIdentity $identity): Account
    {
        $email = strtolower($identity->email);

        if (! $identity->emailVerified || ! in_array($email, config('oceanix.platform_admin_emails', []), true)) {
            throw SocialLoginProviderException::accountNotProvisioned();
        }

        $account = Account::query()->whereRaw('lower(email) = ?', [$email])->firstOrNew(['email' => $email]);

        if ($account->exists && $account->provider_id !== null && $account->provider_id !== $identity->providerId) {
            throw SocialLoginProviderException::accountNotProvisioned();
        }

        $account->forceFill([
            'name' => $identity->name ?: $account->name ?: $email,
            'provider' => strtolower($identity->provider),
            'provider_id' => $identity->providerId,
            'workos_user_id' => $identity->providerId,
            'avatar_url' => $identity->avatarUrl ?: $account->avatar_url,
            'is_platform_admin' => true,
            'status' => 'active',
        ])->save();

        return $account;
    }
}
