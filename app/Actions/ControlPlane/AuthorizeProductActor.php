<?php

namespace App\Actions\ControlPlane;

use App\Enums\Permission;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
use App\Models\Company;
use App\Models\ServicePrincipal;
use App\Models\User;
use App\Services\Account\AccountActorAssertion;
use App\Services\People\PersonProductAccess;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

class AuthorizeProductActor
{
    public function handle(ServicePrincipal $principal, array $input): array
    {
        abort_unless($principal->active && $principal->environment === app()->environment() && $principal->product === $input['product']
            && in_array('actors.authorize', $principal->capabilities ?? [], true) && in_array($input['account_company_uuid'], $principal->company_uuids ?? [], true), 403);
        $hash = $input['payload_hash'];
        unset($input['payload_hash']);
        abort_unless(hash_equals(ApplyProductOperation::hash($input), $hash), 403);
        $binding = AccountCompanyBinding::where('account_company_uuid', $input['account_company_uuid'])->where('workos_organization_id', $input['workos_organization_id'])->first();
        abort_unless($binding, 403);
        $context = app(TenantContext::class);
        $previous = $context->get();
        try {
            $company = Company::findOrFail($binding->compliance_company_id);
            abort_unless($company->status === 'active' && $company->compliance_access_enabled, 403);
            $context->set($company);
            $actor = User::where('workos_user_id', $input['actor_workos_user_id'])->first();
            abort_unless($actor && $actor->status->canAccessTenant() && app(PersonProductAccess::class)->allows($actor), 403);
            $permission = match ($input['kind']) {
                'person.register' => ($input['payload']['registration_source'] ?? 'form') === 'import' ? Permission::PeopleImport : Permission::PeopleManage,
                'person.adopt' => ($input['payload']['adoption_source'] ?? 'command') === 'import' ? Permission::PeopleImport : Permission::PeopleManage,
                'person.access.remove' => Permission::PeopleManage,
                'person.invite' => Permission::PeopleInvite,
                'person.invitation.reconcile' => Permission::PeopleSyncWorkos,
                default => null,
            };
            abort_unless($permission && Gate::forUser($actor)->allows($permission->value), 403);
            if ($input['kind'] === 'person.register' && in_array($input['payload']['registration_mode'] ?? null, ['confirmed-first', 'confirmed-first-mapping'], true)) {
                $operation = AccountRequestOperation::find($input['operation_id']);
                abort_unless($operation && $operation->company_id === $actor->company_id && $operation->actor_id === $actor->id && $operation->actor_workos_user_id === $actor->workos_user_id && $operation->payload['subject_id'] === $input['subject_id'] && $input['subject_id'] === $input['operation_id'] && $input['resource_sequence'] === 1, 403);
                foreach (['account_company_uuid', 'workos_organization_id', 'product'] as $key) {
                    abort_unless($operation->payload[$key] === $input[$key], 403);
                }
                if ($input['payload']['registration_mode'] === 'confirmed-first') {
                    abort_unless(hash_equals($operation->payload_hash, $hash), 403);
                } else {
                    $identity = $operation->receipt['registration']['confirmed_state'] ?? [];
                    abort_unless($operation->user_id && $operation->user_id === ($input['payload']['local_person_id'] ?? null) && ($input['payload']['request_payload_hash'] ?? null) === $operation->payload_hash, 403);
                    foreach (['shared_person_id', 'company_member_id', 'workos_user_id', 'workos_membership_id', 'registration_source'] as $key) {
                        abort_unless(isset($identity[$key]) && $identity[$key] === ($input['payload'][$key] ?? null), 403);
                    }
                }
            }
            if (isset($input['payload']['local_person_id'])) {
                $target = User::whereKey($input['payload']['local_person_id'])->first();
                abort_unless($target && $target->company_id === $actor->company_id, 403);
                if ($input['kind'] === 'person.invite') {
                    Gate::forUser($actor)->authorize('invite', $target);
                }
            }

            return app(AccountActorAssertion::class)->sign($actor, $input);
        } finally {
            $previous ? $context->set($previous) : $context->clear();
        }
    }
}
