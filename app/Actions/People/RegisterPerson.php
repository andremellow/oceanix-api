<?php

namespace App\Actions\People;

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
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
        $operation = DB::transaction(function () use ($actor, $operationId, $name, $email, $import) {
            $existing = AccountRequestOperation::find($operationId);
            if ($existing) {
                abort_unless($existing->actor_id === $actor->id && $existing->company_id === $actor->company_id && $existing->payload['payload']['name'] === trim($name) && $existing->payload['payload']['email'] === strtolower(trim($email)), 409);

                return $existing;
            }
            $binding = AccountCompanyBinding::where('compliance_company_id', $actor->company_id)->firstOrFail();
            abort_if(User::where('email', strtolower(trim($email)))->exists(), 409, __('This person already exists.'));
            $person = User::create(['name' => trim($name), 'email' => strtolower(trim($email)), 'status' => UserStatus::Invited]);
            PersonProductAccess::create(['company_id' => $actor->company_id, 'user_id' => $person->id, 'confirmed_enabled' => false, 'version' => 0, 'operation_id' => $operationId]);
            $payload = ['protocol_version' => 2, 'operation_id' => $operationId, 'kind' => 'person.register', 'product' => 'compliance', 'account_company_uuid' => $binding->account_company_uuid, 'workos_organization_id' => $binding->workos_organization_id,
                'subject_id' => (string) $person->id, 'resource_sequence' => 1, 'actor_workos_user_id' => $actor->workos_user_id,
                'payload' => ['local_person_id' => $person->id, 'name' => $person->name, 'email' => $person->email, 'registration_source' => $import ? 'import' : 'form']];

            return AccountRequestOperation::create(['id' => $operationId, 'company_id' => $actor->company_id, 'user_id' => $person->id, 'actor_id' => $actor->id, 'actor_workos_user_id' => $actor->workos_user_id, 'kind' => 'person.register', 'payload' => $payload, 'payload_hash' => ApplyProductOperation::hash($payload)]);
        });
        if ($operation->state === 'confirmed') {
            return $operation;
        }
        try {
            $client = app(AccountUserClient::class);
            $receipt = $operation->dispatched_at ? $client->receipt($operation->payload) : null;
            if (! $receipt) {
                Gate::forUser($actor)->authorize(($import ? Permission::PeopleImport : Permission::PeopleManage)->value);
                $operation->update(['dispatched_at' => now()]);
                $receipt = $client->submit($actor, $operation->payload);
            }
            if ($receipt['outcome'] !== 'confirmed') {
                $operation->update(['state' => $receipt['outcome'], 'receipt' => $receipt, 'safe_error' => 'confirmation_pending']);

                return $operation->fresh();
            }
            // Confirmation also requires the receiver's independent local projection.
            $access = PersonProductAccess::where('company_id', $operation->company_id)->where('user_id', $operation->user_id)->firstOrFail();
            abort_unless($access->version >= 1 && $access->confirmed_enabled, 409);
            $person = User::findOrFail($operation->user_id);
            $employee = Role::where('key', 'employee')->first();
            if ($employee) {
                $person->roles()->syncWithoutDetaching($employee->id);
            }
            $operation->update(['state' => 'confirmed', 'receipt' => $receipt, 'safe_error' => null]);
        } catch (Throwable $error) {
            $operation->update(['state' => 'unknown', 'safe_error' => 'confirmation_unavailable']);
        }

        return $operation->fresh();
    }
}
