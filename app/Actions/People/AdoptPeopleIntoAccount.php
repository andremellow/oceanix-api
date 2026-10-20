<?php

namespace App\Actions\People;

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Enums\Permission;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
use App\Models\Company;
use App\Models\PersonAccountLink;
use App\Models\User;
use App\Services\Account\AccountUserClient;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class AdoptPeopleIntoAccount
{
    public function handle(User $actor, bool $dryRun = true, ?array $personIds = null, bool $import = false): array
    {
        Gate::forUser($actor)->authorize(($import ? Permission::PeopleImport : Permission::PeopleManage)->value);
        abort_unless($actor->company_id === app(TenantContext::class)->id(), 403);
        $results = [];
        foreach (User::query()->when($personIds !== null, fn ($q) => $q->whereIn('id', $personIds))->orderBy('id')->cursor() as $person) {
            if (PersonAccountLink::where('company_id', $person->company_id)->where('user_id', $person->id)->exists()) {
                $results[] = ['person_id' => $person->id, 'outcome' => 'already_linked'];

                continue;
            }
            if ($dryRun) {
                $results[] = ['person_id' => $person->id, 'outcome' => ($person->workos_user_id || $person->workos_invitation_id) ? 'ready' : 'mapping_required'];

                continue;
            }
            try {
                $this->ensure($actor, $person, $import ? 'person.import' : 'person.adopt');
                $results[] = ['person_id' => $person->id, 'outcome' => 'confirmed'];
            } catch (\Throwable) {
                $results[] = ['person_id' => $person->id, 'outcome' => 'mapping_required'];
            }
        }

        return $results;
    }

    public function ensure(User $actor, User $person, string $sourceKind): PersonAccountLink
    {
        abort_unless($actor->company_id === $person->company_id && $actor->company_id === app(TenantContext::class)->id(), 403);
        $permission = match ($sourceKind) {
            'person.invite' => Permission::PeopleInvite,
            'person.invitation.reconcile' => Permission::PeopleSyncWorkos,
            'person.import' => Permission::PeopleImport,
            default => Permission::PeopleManage,
        };
        Gate::forUser($actor->fresh())->authorize($permission->value);
        $link = PersonAccountLink::where('company_id', $person->company_id)->where('user_id', $person->id)->first();
        $uninitialized = $person->workos_invitation_id === null && (int) $person->invitation_generation === 0
            && (int) $person->invitation_observation_sequence === 0 && $person->invitation_observation_hash === null && $person->invitation_verified_at === null;
        if ($link && ($sourceKind !== 'person.invitation.reconcile' || ! $uninitialized)) {
            return $link;
        }
        abort_unless($person->workos_user_id || $person->workos_invitation_id, 409, 'mapping_required');
        $binding = AccountCompanyBinding::where('compliance_company_id', $person->company_id)->firstOrFail();
        // Freeze a source intent before requesting its narrowly inherited mapping authority.
        $sourceId = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'mapping-baseline-v1-source:'.$person->company_id.':'.$person->id.':'.$actor->id.':'.$sourceKind.':'.$person->invitation_generation);
        $sourcePayload = ['protocol_version' => 2, 'operation_id' => $sourceId, 'kind' => $sourceKind, 'product' => 'compliance', 'account_company_uuid' => $binding->account_company_uuid, 'workos_organization_id' => $binding->workos_organization_id, 'subject_id' => (string) $person->id, 'resource_sequence' => max(1, $person->invitation_generation + 1), 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => ['local_person_id' => $person->id, 'mapping_prerequisite' => true]];
        $source = AccountRequestOperation::firstOrCreate(['id' => $sourceId], ['company_id' => $person->company_id, 'user_id' => $person->id, 'actor_id' => $actor->id, 'kind' => $sourceKind, 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => $sourcePayload, 'payload_hash' => ApplyProductOperation::hash($sourcePayload)]);
        $id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'mapping:'.$sourceId);
        $payload = [...$source->payload, 'operation_id' => $id, 'kind' => 'person.map-existing', 'payload' => ['local_person_id' => $person->id, 'workos_user_id' => $person->workos_user_id, 'invitation_id' => $person->workos_invitation_id, 'generation' => $person->invitation_generation, 'source_operation_id' => $source->id, 'source_kind' => $sourceKind, 'source_payload_hash' => $source->payload_hash]];
        $operation = AccountRequestOperation::firstOrCreate(['id' => $id], ['company_id' => $person->company_id, 'user_id' => $person->id, 'actor_id' => $actor->id, 'kind' => 'person.map-existing', 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => $payload, 'payload_hash' => ApplyProductOperation::hash($payload)]);
        $client = app(AccountUserClient::class);
        $receipt = $operation->dispatched_at ? $client->receipt($operation->payload) : null;
        if (! $receipt) {
            Gate::forUser($actor->fresh())->authorize($permission->value);
            $operation->update(['dispatched_at' => now()]);
            $receipt = $client->submit($actor, $operation->payload);
        }
        $state = $receipt['confirmed_state'];
        abort_unless($receipt['outcome'] === 'confirmed' && ($state['mapping_complete'] ?? null) === true && (string) ($state['local_person_id'] ?? '') === (string) $person->id && Str::isUuid($state['shared_person_id'] ?? '') && Str::isUuid($state['company_member_id'] ?? ''), 409);

        return DB::transaction(function () use ($person, $binding, $operation, $receipt, $state) {
            Company::whereKey($person->company_id)->lockForUpdate()->firstOrFail();
            $locked = User::whereKey($person->id)->lockForUpdate()->firstOrFail();
            AccountRequestOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->workos_user_id === $operation->payload['payload']['workos_user_id']
                && AccountCompanyBinding::where('compliance_company_id', $locked->company_id)->where('account_company_uuid', $binding->account_company_uuid)->where('workos_organization_id', $binding->workos_organization_id)->exists(), 409, 'identity_conflict');
            $link = PersonAccountLink::firstOrCreate(['company_id' => $person->company_id, 'user_id' => $person->id], ['account_company_uuid' => $binding->account_company_uuid, 'shared_person_id' => $state['shared_person_id'], 'company_member_id' => $state['company_member_id'], 'provenance' => ['operation_id' => $operation->id, 'kind' => 'person.map-existing']]);
            abort_unless($link->account_company_uuid === $binding->account_company_uuid && $link->shared_person_id === $state['shared_person_id'] && $link->company_member_id === $state['company_member_id'], 409);
            $baseline = $state['invitation_baseline'] ?? null;
            if ($locked->workos_invitation_id === null && (int) $locked->invitation_generation === 0 && (int) $locked->invitation_observation_sequence === 0
                && $locked->invitation_observation_hash === null && $locked->invitation_verified_at === null && $baseline !== null) {
                abort_unless(is_array($baseline) && array_key_exists('invitation_id', $baseline) && ($baseline['generation'] ?? null) === 0
                    && ($baseline['invitation_id'] === null || (is_string($baseline['invitation_id']) && $baseline['invitation_id'] !== '')), 409, 'invitation_conflict');
                $locked->forceFill(['workos_invitation_id' => $baseline['invitation_id']])->save();
            }
            $operation->update(['state' => 'confirmed', 'receipt' => $receipt, 'safe_error' => null]);
            AccountRequestOperation::whereKey($operation->payload['payload']['source_operation_id'])->update(['state' => 'confirmed', 'receipt' => ['mapping_operation_id' => $operation->id, 'mapping_complete' => true]]);

            return $link;
        });
    }
}
