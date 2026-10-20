<?php

namespace App\Actions\Platform;

use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Account\AccountUserClient;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\PlatformAccess;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use RuntimeException;

class InviteCompanyAdministrator
{
    public function __construct(
        private readonly PlatformAccess $access,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly AccountUserClient $accountClient,
    ) {}

    /** @return array{person: User, invitation_sent: bool} */
    public function handle(Company $company, string $name, string $email): array
    {
        $account = $this->access->authorize();

        if (blank($company->workos_organization_id)) {
            throw new RuntimeException(__('Provision this company in WorkOS before sending invitations.'));
        }

        $previous = $this->context->get();

        try {
            $this->context->set($company);
            (new PermissionSeeder)->run();
            (new RoleSeeder)->run();

            $normalizedEmail = strtolower(trim($email));
            $existingAccount = Account::query()->whereRaw('lower(email) = ?', [$normalizedEmail])->first();
            $person = User::query()->firstOrNew(['email' => $normalizedEmail]);
            $person->forceFill([
                'account_id' => $existingAccount?->id ?: $person->account_id,
                'name' => trim($name),
                'email' => $normalizedEmail,
                'provider' => $existingAccount?->provider ?: $person->provider,
                'provider_id' => $existingAccount?->provider_id ?: $person->provider_id,
                'workos_user_id' => $existingAccount?->workos_user_id ?: $person->workos_user_id,
                'status' => $person->exists ? $person->status : UserStatus::Invited,
            ])->save();

            $membership = $this->accountClient->platformOperation($account, $company, $person, 'person.membership.ensure');
            abort_unless($account->fresh()->is_platform_admin && $account->fresh()->status === 'active', 403);
            $this->access->authorize();
            $person->roles()->syncWithoutDetaching(Role::query()->where('key', 'admin')->firstOrFail());
            $this->audit->log('platform.company_administrator_granted', $person, after: [
                'email' => $person->email, 'role' => 'admin',
            ], metadata: ['platform_account_id' => $account->id, 'workos_organization_membership_id' => $membership['workos_membership_id']]);
            $state = $this->accountClient->platformOperation($account, $company, $person->fresh(), 'person.invite');

            if (($state['outcome'] ?? null) !== 'confirmed' && ! array_key_exists('sent', $state)) {
                throw new RuntimeException(__('The invitation is awaiting confirmation from Account.'));
            }

            return ['person' => $person->fresh(), 'invitation_sent' => ($state['sent'] ?? false) === true];

        } finally {
            $previous === null ? $this->context->clear() : $this->context->set($previous);
        }
    }
}
