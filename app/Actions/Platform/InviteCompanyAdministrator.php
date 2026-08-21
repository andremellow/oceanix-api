<?php

namespace App\Actions\Platform;

use App\Actions\People\SendWorkosInvitation;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\PlatformAccess;
use App\Services\Workos\WorkosOrganizationMembershipService;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use RuntimeException;

class InviteCompanyAdministrator
{
    public function __construct(
        private readonly PlatformAccess $access,
        private readonly TenantContext $context,
        private readonly SendWorkosInvitation $sendInvitation,
        private readonly AuditLogger $audit,
        private readonly WorkosOrganizationMembershipService $memberships,
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
                'status' => UserStatus::Active,
            ])->save();

            $person->roles()->syncWithoutDetaching(Role::query()->where('key', 'admin')->firstOrFail());
            $this->audit->log('platform.company_administrator_granted', $person, after: [
                'email' => $person->email,
                'role' => 'admin',
            ], metadata: ['platform_account_id' => $account->id]);

            if (filled($person->workos_user_id)) {
                $membershipId = $this->memberships->ensure($person, $company);
                $this->audit->log('platform.company_workos_membership_created', $person, metadata: [
                    'platform_account_id' => $account->id,
                    'workos_organization_membership_id' => $membershipId,
                ]);

                return ['person' => $person->fresh(), 'invitation_sent' => false];
            }

            return ['person' => $this->sendInvitation->handle($person), 'invitation_sent' => true];
        } finally {
            $previous === null ? $this->context->clear() : $this->context->set($previous);
        }
    }
}
