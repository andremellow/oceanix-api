<?php

namespace App\Actions\People;

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Enums\Permission;
use App\Models\AccountRequestOperation;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\User;
use App\Services\Account\AccountUserClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ChangePersonProductAccess
{
    public function handle(User $actor, User $person, string $operationId): AccountRequestOperation
    {
        abort_unless($actor->company_id === $person->company_id && $actor->workos_user_id, 403);
        Gate::forUser($actor)->authorize(Permission::PeopleManage->value);
        $operation = DB::transaction(function () use ($actor, $person, $operationId) {
            if ($existing = AccountRequestOperation::find($operationId)) {
                abort_unless($existing->company_id === $person->company_id && $existing->user_id === $person->id && $existing->actor_id === $actor->id && $existing->kind === 'person.access.remove', 409);

                return $existing;
            }
            $link = PersonAccountLink::where('company_id', $person->company_id)->where('user_id', $person->id)->firstOrFail();
            $access = PersonProductAccess::where('company_id', $person->company_id)->where('user_id', $person->id)->firstOrFail();
            $payload = ['protocol_version' => 2, 'operation_id' => $operationId, 'kind' => 'person.access.remove', 'product' => 'compliance', 'account_company_uuid' => $link->account_company_uuid, 'workos_organization_id' => $person->company->workos_organization_id, 'subject_id' => $link->company_member_id, 'resource_sequence' => $access->version + 1, 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => ['local_person_id' => $person->id, 'enabled' => false]];

            return AccountRequestOperation::create(['id' => $operationId, 'company_id' => $person->company_id, 'user_id' => $person->id, 'actor_id' => $actor->id, 'actor_workos_user_id' => $actor->workos_user_id, 'kind' => 'person.access.remove', 'payload' => $payload, 'payload_hash' => ApplyProductOperation::hash($payload)]);
        });
        if ($operation->state === 'confirmed') {
            return $operation;
        }
        try {
            $client = app(AccountUserClient::class);
            $receipt = $operation->dispatched_at ? $client->receipt($operation->payload) : null;
            if (! $receipt) {
                Gate::forUser($actor)->authorize(Permission::PeopleManage->value);
                $operation->update(['dispatched_at' => now()]);
                $receipt = $client->submit($actor, $operation->payload);
            }
            $access = PersonProductAccess::where('company_id', $person->company_id)->where('user_id', $person->id)->firstOrFail();
            if ($receipt['outcome'] === 'confirmed') {
                abort_unless(! $access->confirmed_enabled && $access->version >= $operation->payload['resource_sequence'], 409);
            }
            $operation->update(['state' => $receipt['outcome'], 'receipt' => $receipt, 'safe_error' => $receipt['outcome'] === 'confirmed' ? null : 'confirmation_pending']);
        } catch (Throwable) {
            $operation->update(['state' => 'unknown', 'safe_error' => 'confirmation_unavailable']);
        }

        return $operation->fresh();
    }
}
