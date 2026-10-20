<?php

namespace App\Actions\Platform;

use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Account\AccountUserClient;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\PlatformAccess;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

class GrantPlatformCompanyAccess
{
    public function __construct(
        private readonly PlatformAccess $access,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly AccountUserClient $accountClient,
    ) {}

    public function handle(Company $company): User
    {
        $account = $this->access->authorize();
        $previous = $this->context->get();

        try {
            $this->context->set($company);
            (new PermissionSeeder)->run();
            (new RoleSeeder)->run();

            $person = User::query()
                ->where('account_id', $account->id)
                ->orWhereRaw('lower(email) = ?', [strtolower($account->email)])
                ->first() ?? new User;

            abort_if($person->exists && $person->account_id !== null && $person->account_id !== $account->id, 409);

            $person->forceFill([
                'account_id' => $account->id,
                'name' => $account->name,
                'email' => strtolower($account->email),
                'email_verified_at' => $person->email_verified_at ?? now(),
                'provider' => $account->provider,
                'provider_id' => $account->provider_id,
                'workos_user_id' => $account->workos_user_id,
                'status' => $person->exists ? $person->status : UserStatus::Invited,
            ])->save();

            $state = $this->accountClient->platformOperation($account, $company, $person, 'person.membership.ensure');
            $membershipId = $state['workos_membership_id'];
            abort_unless($account->fresh()->is_platform_admin && $account->fresh()->status === 'active', 403);
            $this->access->authorize();
            $person->roles()->syncWithoutDetaching(Role::query()->where('key', 'admin')->firstOrFail());
            $this->audit->log('platform.company_admin_access_granted', $person, after: [
                'account_id' => $account->id,
                'role' => 'admin',
            ], metadata: [
                'platform_account_id' => $account->id,
                'workos_organization_membership_id' => $membershipId,
            ]);

            return $person->fresh('roles');
        } finally {
            $previous === null ? $this->context->clear() : $this->context->set($previous);
        }
    }
}
