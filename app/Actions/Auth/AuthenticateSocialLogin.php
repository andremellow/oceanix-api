<?php

namespace App\Actions\Auth;

use App\Data\SocialIdentity;
use App\Enums\UserStatus;
use App\Exceptions\SocialLoginProviderException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the local corporate user behind an identity-provider sign-in.
 *
 * Identity lives in WorkOS; the training obligations live here. A local user must exist for
 * every person, so an unprovisioned identity is rejected unless auto-provisioning is on.
 */
class AuthenticateSocialLogin
{
    public function handle(SocialIdentity $identity): User
    {
        return DB::transaction(function () use ($identity): User {
            $provider = strtolower($identity->provider);
            $email = strtolower($identity->email);

            $user = User::query()
                ->where('provider', $provider)
                ->where('provider_id', $identity->providerId)
                ->first();

            if ($user === null) {
                $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

                if ($user !== null) {
                    // Only adopt an existing local account when the provider asserts the
                    // email is verified; otherwise an unverified identity could bind itself
                    // to someone else's account.
                    if (! $identity->emailVerified) {
                        throw SocialLoginProviderException::emailNotVerified();
                    }

                    $user->forceFill([
                        'provider' => $provider,
                        'provider_id' => $identity->providerId,
                        'workos_user_id' => $provider === 'workos' ? $identity->providerId : $user->workos_user_id,
                    ])->save();
                }
            }

            if ($user === null) {
                if (! config('oceanix.auto_provision_users')) {
                    throw SocialLoginProviderException::accountNotProvisioned();
                }

                $user = User::query()->create([
                    'name' => $identity->name ?: $email,
                    'email' => $email,
                    'email_verified_at' => now(),
                    'avatar_url' => $identity->avatarUrl,
                    'provider' => $provider,
                    'provider_id' => $identity->providerId,
                    'workos_user_id' => $provider === 'workos' ? $identity->providerId : null,
                    'status' => UserStatus::Active,
                ]);
            }

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
