<?php

namespace App\Actions\Auth;

use App\Data\SocialIdentity;
use App\Exceptions\SocialLoginProviderException;
use App\Models\Account;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the local corporate user behind an identity-provider sign-in.
 *
 * Identity lives in WorkOS; the training obligations live here. A local user must exist for
 * every person, so an unprovisioned identity is always rejected.
 */
class AuthenticateSocialLogin
{
    public function handle(SocialIdentity $identity): User
    {
        return DB::transaction(function () use ($identity): User {
            $provider = strtolower($identity->provider);
            $email = strtolower($identity->email);

            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($user === null) {
                throw SocialLoginProviderException::accountNotProvisioned();
            }

            if (! $identity->emailVerified) {
                throw SocialLoginProviderException::emailNotVerified();
            }

            $account = Account::query()
                ->where('provider', $provider)
                ->where('provider_id', $identity->providerId)
                ->first() ?? Account::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($account !== null && strtolower($account->email) !== $email) {
                throw SocialLoginProviderException::accountNotProvisioned();
            }

            $account ??= Account::query()->create([
                'name' => $identity->name ?: $user->name,
                'email' => $email,
                'status' => 'active',
            ]);

            if ($account->status !== 'active') {
                throw SocialLoginProviderException::accountInactive();
            }

            $account->forceFill([
                'name' => $identity->name ?: $account->name,
                'provider' => $provider,
                'provider_id' => $identity->providerId,
                'workos_user_id' => $provider === 'workos' ? $identity->providerId : $account->workos_user_id,
                'avatar_url' => $identity->avatarUrl ?: $account->avatar_url,
                'is_platform_admin' => $account->is_platform_admin || in_array($email, config('oceanix.platform_admin_emails', []), true),
            ])->save();

            if ($user->account_id !== null && $user->account_id !== $account->id) {
                throw SocialLoginProviderException::accountNotProvisioned();
            }

            $user->forceFill([
                'account_id' => $account->id,
                'provider' => $provider,
                'provider_id' => $identity->providerId,
                'workos_user_id' => $provider === 'workos' ? $identity->providerId : $user->workos_user_id,
                'email_verified_at' => now(),
                'avatar_url' => $identity->avatarUrl ?: $user->avatar_url,
            ])->save();

            // A terminated or suspended person keeps their historical evidence but cannot
            // sign in — revoking access is part of the compliance boundary, and it applies
            // to administrators too.
            if (! $user->status->isEligibleForTraining()) {
                throw SocialLoginProviderException::accountInactive();
            }

            $this->syncBootstrapRoles($user);

            if ($user->name === $email && $identity->name !== null) {
                $user->forceFill(['name' => $identity->name])->save();
            }

            return $user->fresh();
        });
    }

    /** Bootstrap roles so a fresh installation has a working administrator. */
    private function syncBootstrapRoles(User $user): void
    {
        if ($this->isConfiguredAdmin($user)) {
            $admin = Role::query()->where('key', 'admin')->first();

            if ($admin !== null) {
                $user->roles()->syncWithoutDetaching($admin);
            }
        }

        if ($user->roles()->count() === 0) {
            $employee = Role::query()->where('key', 'employee')->first();

            if ($employee !== null) {
                $user->roles()->syncWithoutDetaching($employee);
            }
        }
    }

    private function isConfiguredAdmin(User $user): bool
    {
        return in_array(strtolower($user->email), config('oceanix.admin_emails', []), true);
    }
}
