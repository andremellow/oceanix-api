<?php

namespace App\Actions\People;

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
use App\Models\Company;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\Role;
use App\Models\User;
use App\Services\Account\AccountUserClient;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Throwable;

class RegisterPerson
{
    public function handle(User $actor, string $operationId, string $name, string $email, bool $import = false): AccountRequestOperation
    {
        abort_unless($actor->company_id === app(TenantContext::class)->id() && $actor->workos_user_id, 403);
        Gate::forUser($actor)->authorize(($import ? Permission::PeopleImport : Permission::PeopleManage)->value);
        Validator::make(['id' => $operationId, 'name' => $name, 'email' => $email], ['id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255']])->validate();
        $name = trim($name);
        $email = strtolower(trim($email));
        $operation = DB::transaction(function () use ($actor, $operationId, $name, $email, $import) {
            Company::whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
            $existing = AccountRequestOperation::find($operationId);
            if ($existing) {
                abort_unless($existing->actor_id === $actor->id && $existing->company_id === $actor->company_id && $existing->payload['payload']['name'] === trim($name) && $existing->payload['payload']['email'] === strtolower(trim($email)) && ($existing->payload['payload']['registration_source'] ?? 'form') === ($import ? 'import' : 'form'), 409);

                return $existing;
            }
            $binding = AccountCompanyBinding::where('compliance_company_id', $actor->company_id)->firstOrFail();
            $reserved = AccountRequestOperation::where('company_id', $actor->company_id)->where('kind', 'person.register')->where('state', '!=', 'confirmed')->get()->first(fn ($request) => ($request->payload['payload']['email'] ?? null) === $email);
            if ($reserved) {
                abort_unless($reserved->actor_id === $actor->id && ($reserved->payload['payload']['registration_source'] ?? 'form') === ($import ? 'import' : 'form') && $reserved->payload['payload']['name'] === $name, 409);

                return $reserved;
            }
            abort_if(User::where('email', strtolower(trim($email)))->exists(), 409, __('This person already exists.'));
            $payload = ['protocol_version' => 2, 'operation_id' => $operationId, 'kind' => 'person.register', 'product' => 'compliance', 'account_company_uuid' => $binding->account_company_uuid, 'workos_organization_id' => $binding->workos_organization_id,
                'subject_id' => $operationId, 'resource_sequence' => 1, 'actor_workos_user_id' => $actor->workos_user_id,
                'payload' => ['registration_mode' => 'confirmed-first', 'name' => $name, 'email' => $email, 'registration_source' => $import ? 'import' : 'form']];

            return AccountRequestOperation::create(['id' => $operationId, 'company_id' => $actor->company_id, 'actor_id' => $actor->id, 'actor_workos_user_id' => $actor->workos_user_id, 'kind' => 'person.register', 'payload' => $payload, 'payload_hash' => ApplyProductOperation::hash($payload)]);
        });
        if ($operation->state === 'confirmed') {
            return $operation;
        }
        try {
            $client = app(AccountUserClient::class);
            $receipt = $operation->dispatched_at ? $client->receipt($operation->payload) : null;
            if (! $receipt) {
                $this->authorizeCurrent($actor, $operation, $import);
                $operation->update(['dispatched_at' => now()]);
                $receipt = $client->submit($actor, $operation->payload);
            }
            if ($receipt['outcome'] !== 'confirmed') {
                $this->pending($operation, ['state' => $receipt['outcome'], 'receipt' => $receipt, 'safe_error' => 'confirmation_pending']);

                return $operation->fresh();
            }
            if (($operation->payload['payload']['registration_mode'] ?? null) !== 'confirmed-first') {
                $this->finishLegacy($actor, $operation, $receipt, $import);

                return $operation->fresh();
            }
            $client->validateRegistrationReceipt($operation->payload, $receipt);
            $operation = DB::transaction(function () use ($actor, $operation, $receipt, $import) {
                $current = $this->locked($actor, $operation, $import);
                if ($current->state === 'confirmed') {
                    return $current;
                }
                app(AccountUserClient::class)->validateRegistrationReceipt($current->payload, $receipt);
                $identity = $receipt['confirmed_state'];
                if (! $current->user_id) {
                    abort_if(User::where('email', $current->payload['payload']['email'])->orWhere('workos_user_id', $identity['workos_user_id'])->exists(), 409);
                    $person = User::create(['name' => $current->payload['payload']['name'], 'email' => $current->payload['payload']['email'], 'status' => UserStatus::Invited, 'workos_user_id' => $identity['workos_user_id']]);
                    PersonAccountLink::create(['company_id' => $current->company_id, 'user_id' => $person->id, 'account_company_uuid' => $current->payload['account_company_uuid'], 'shared_person_id' => $identity['shared_person_id'], 'company_member_id' => $identity['company_member_id'], 'provenance' => ['registration_operation_id' => $current->id]]);
                    PersonProductAccess::create(['company_id' => $current->company_id, 'user_id' => $person->id, 'confirmed_enabled' => false, 'version' => 0, 'operation_id' => $current->id]);
                    $current->linkConfirmedPerson($person->id, $receipt);
                } else {
                    $stored = $current->receipt['registration']['confirmed_state'] ?? [];
                    foreach (['shared_person_id', 'company_member_id', 'workos_user_id', 'workos_membership_id'] as $key) {
                        abort_unless(($stored[$key] ?? null) === $identity[$key], 409);
                    }
                }

                return $current;
            });
            if ($operation->state === 'confirmed') {
                return $operation;
            }
            $mapping = $operation->payload;
            $identity = $operation->receipt['registration']['confirmed_state'];
            $mapping['payload'] = array_intersect_key($identity, array_flip(['request_payload_hash', 'registration_source', 'shared_person_id', 'company_member_id', 'workos_user_id', 'workos_membership_id'])) + ['registration_mode' => 'confirmed-first-mapping', 'local_person_id' => $operation->user_id];
            $this->authorizeCurrent($actor, $operation, $import);
            $acknowledgement = $client->finalizeRegistration($actor, $mapping);
            DB::transaction(function () use ($actor, $operation, $acknowledgement, $mapping, $import) {
                $current = $this->locked($actor, $operation, $import);
                if ($current->state === 'confirmed') {
                    return;
                }
                app(AccountUserClient::class)->validateMappingReceipt($mapping, $acknowledgement);
                $person = User::findOrFail($current->user_id);
                abort_unless($person->workos_user_id === $mapping['payload']['workos_user_id'], 409);
                $access = PersonProductAccess::where('company_id', $current->company_id)->where('user_id', $current->user_id)->lockForUpdate()->firstOrFail();
                abort_unless($access->operation_id === $current->id && $access->version === 0 && ! $access->confirmed_enabled, 409);
                $employee = Role::where('key', 'employee')->firstOrFail();
                $person->roles()->syncWithoutDetaching($employee->id);
                $access->update(['confirmed_enabled' => true, 'version' => 1]);
                $current->update(['state' => 'confirmed', 'receipt' => ['registration' => $current->receipt['registration'], 'mapping' => $acknowledgement], 'safe_error' => null]);
            });
        } catch (Throwable $error) {
            $this->pending($operation, ['state' => $operation->fresh()->user_id && ($operation->payload['payload']['registration_mode'] ?? null) === 'confirmed-first' ? 'mapping_pending' : 'unknown', 'safe_error' => 'confirmation_unavailable']);
        }

        return $operation->fresh();
    }

    private function authorizeCurrent(User $actor, AccountRequestOperation $operation, bool $import): void
    {
        $actor->refresh();
        abort_unless($actor->company_id === $operation->company_id && $actor->company_id === app(TenantContext::class)->id() && $actor->workos_user_id === $operation->actor_workos_user_id && $actor->status->canAccessTenant() && app(\App\Services\People\PersonProductAccess::class)->allows($actor), 403);
        Gate::forUser($actor)->authorize(($import ? Permission::PeopleImport : Permission::PeopleManage)->value);
        $company = Company::findOrFail($operation->company_id);
        abort_unless($company->status === 'active' && $company->compliance_access_enabled, 403);
        abort_unless(AccountCompanyBinding::where('compliance_company_id', $operation->company_id)->where('account_company_uuid', $operation->payload['account_company_uuid'])->where('workos_organization_id', $operation->payload['workos_organization_id'])->exists(), 403);
    }

    private function locked(User $actor, AccountRequestOperation $operation, bool $import): AccountRequestOperation
    {
        Company::whereKey($operation->company_id)->lockForUpdate()->firstOrFail();
        $current = AccountRequestOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
        $this->authorizeCurrent($actor, $current, $import);

        return $current;
    }

    private function pending(AccountRequestOperation $operation, array $updates): void
    {
        DB::transaction(function () use ($operation, $updates) {
            Company::whereKey($operation->company_id)->lockForUpdate()->firstOrFail();
            $current = AccountRequestOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($current->state !== 'confirmed') {
                if ($current->user_id && ($current->payload['payload']['registration_mode'] ?? null) === 'confirmed-first') {
                    unset($updates['receipt']);
                    $updates['state'] = 'mapping_pending';
                }
                $current->update($updates);
            }
        });
    }

    private function finishLegacy(User $actor, AccountRequestOperation $operation, array $receipt, bool $import): void
    {
        DB::transaction(function () use ($actor, $operation, $receipt, $import) {
            $current = $this->locked($actor, $operation, $import);
            if ($current->state === 'confirmed') {
                return;
            }
            $access = PersonProductAccess::where('company_id', $current->company_id)->where('user_id', $current->user_id)->firstOrFail();
            abort_unless($access->version >= 1 && $access->confirmed_enabled, 409);
            $person = User::findOrFail($current->user_id);
            $person->roles()->syncWithoutDetaching(Role::where('key', 'employee')->firstOrFail()->id);
            $current->update(['state' => 'confirmed', 'receipt' => $receipt, 'safe_error' => null]);
        });
    }
}
