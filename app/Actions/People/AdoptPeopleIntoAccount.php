<?php

namespace App\Actions\People;

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Enums\Permission;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\User;
use App\Services\Account\AccountUserClient;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Ramsey\Uuid\Uuid;
use Throwable;

class AdoptPeopleIntoAccount
{
    public function handle(User $actor, bool $dryRun = true, ?array $personIds = null, bool $import = false): array
    {
        Gate::forUser($actor)->authorize(($import ? Permission::PeopleImport : Permission::PeopleManage)->value);
        abort_unless($actor->company_id === app(TenantContext::class)->id() && $actor->workos_user_id, 403);
        $binding = AccountCompanyBinding::where('compliance_company_id', $actor->company_id)->firstOrFail();
        $results = [];
        $query = User::query()->orderBy('id');
        if ($personIds !== null) {
            $query->whereIn('id', $personIds);
        }
        foreach ($query->cursor() as $person) {
            $link = PersonAccountLink::where('company_id', $person->company_id)->where('user_id', $person->id)->first();
            if ($link) {
                $results[] = ['person_id' => $person->id, 'outcome' => 'already_linked'];

                continue;
            }
            $projection = PersonProductAccess::where('company_id', $person->company_id)->where('user_id', $person->id)->first();
            $invitationFields = ['invitation_state', 'invitation_generation', 'invitation_sent_at', 'invitation_accepted_at', 'invitation_expires_at', 'invitation_revoked_at', 'invitation_verified_at', 'invitation_accepted_history_at'];
            $invitation = ['id' => $person->workos_invitation_id, 'state' => $person->invitation_state?->value, 'generation' => $person->invitation_generation];
            foreach ($invitationFields as $field) {
                $invitation[$field] = $person->getRawOriginal($field);
            }
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'adopt:'.$binding->account_company_uuid.':'.$person->id)->toString();
            $payload = ['protocol_version' => 2, 'operation_id' => $id, 'kind' => 'person.adopt', 'product' => 'compliance', 'account_company_uuid' => $binding->account_company_uuid, 'workos_organization_id' => $binding->workos_organization_id, 'subject_id' => (string) $person->id, 'resource_sequence' => max(1, $projection?->version ?? 1), 'actor_workos_user_id' => $actor->workos_user_id,
                'payload' => ['adoption_source' => $import ? 'import' : 'command', 'local_person_id' => $person->id, 'name' => $person->name, 'email' => $person->email, 'observed_enabled' => $projection?->confirmed_enabled ?? $person->status->canAccessTenant(), 'provider_active' => (bool) $person->workos_active_membership, 'workos_user_id' => $person->workos_user_id, 'workos_membership_id' => null, 'invitation' => $invitation, 'evidence' => ['first_access_at' => $person->getRawOriginal('first_access_at'), 'last_access_at' => $person->getRawOriginal('last_access_at'), 'status' => $person->getRawOriginal('status'), 'account_id' => $person->account_id]]];
            if ($dryRun) {
                $results[] = ['person_id' => $person->id, 'outcome' => 'ready'];

                continue;
            }
            $operation = AccountRequestOperation::firstOrCreate(['id' => $id], ['company_id' => $person->company_id, 'user_id' => $person->id, 'actor_id' => $actor->id, 'kind' => 'person.adopt', 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => $payload, 'payload_hash' => ApplyProductOperation::hash($payload)]);
            try {
                $client = app(AccountUserClient::class);
                $receipt = $operation->dispatched_at ? $client->receipt($operation->payload) : null;
                if (! $receipt) {
                    Gate::forUser($actor)->authorize(($import ? Permission::PeopleImport : Permission::PeopleManage)->value);
                    abort_unless($actor->workos_user_id === $operation->actor_workos_user_id, 403);
                    $operation->update(['dispatched_at' => now()]);
                    $receipt = $client->submit($actor, $operation->payload);
                }
                if ($receipt['outcome'] !== 'confirmed') {
                    $operation->update(['state' => $receipt['outcome'], 'receipt' => $receipt]);
                } else {
                    $state = $receipt['confirmed_state'];
                    abort_unless(is_string($state['shared_person_id'] ?? null) && is_string($state['company_member_id'] ?? null), 409);
                    DB::transaction(function () use ($person, $operation, $receipt, $state, $binding) {
                        PersonAccountLink::firstOrCreate(['company_id' => $person->company_id, 'user_id' => $person->id], ['account_company_uuid' => $binding->account_company_uuid, 'shared_person_id' => $state['shared_person_id'], 'company_member_id' => $state['company_member_id'], 'provenance' => $operation->payload['payload']]);
                        PersonProductAccess::firstOrCreate(['company_id' => $person->company_id, 'user_id' => $person->id], ['confirmed_enabled' => $operation->payload['payload']['observed_enabled'], 'version' => $operation->payload['resource_sequence'], 'operation_id' => $operation->id]);
                        $operation->update(['state' => 'confirmed', 'receipt' => $receipt, 'safe_error' => null]);
                    });
                }
            } catch (Throwable) {
                $operation->update(['state' => 'unknown', 'safe_error' => 'adoption_unconfirmed']);
            }
            $results[] = ['person_id' => $person->id, 'outcome' => $operation->fresh()->state];
        }

        return $results;
    }
}
