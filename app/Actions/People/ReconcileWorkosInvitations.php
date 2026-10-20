<?php

namespace App\Actions\People;

use App\Actions\ControlPlane\ApplyInvitationObservation;
use App\Actions\ControlPlane\ApplyProductOperation;
use App\Enums\Permission;
use App\Enums\WorkosOperationStatus;
use App\Models\AccountRequestOperation;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkosInvitationAttempt;
use App\Models\WorkosSyncRun;
use App\Services\Account\AccountUserClient;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class ReconcileWorkosInvitations
{
    public function authorizedActor(int $companyId, ?int $actorId, Permission $permission): ?User
    {
        $actor = User::withoutGlobalScope('company')->where('company_id', $companyId)->whereKey($actorId)->first();
        if (! $actor || ! $actor->status->canAccessTenant() || (! $actor->account || $actor->account->status !== 'active') || ! Gate::forUser($actor)->allows($permission->value)) {
            return null;
        }

        return $actor;
    }

    public function handle(WorkosSyncRun $run): void
    {
        abort_unless($run->company_id === app(TenantContext::class)->id(), 403);
        if (! $run->status->isOpen()) {
            return;
        }
        $actor = $this->authorizedActor($run->company_id, $run->actor_id, Permission::PeopleSyncWorkos);
        if (! $actor) {
            $run->update(['status' => WorkosOperationStatus::Failed, 'reason' => 'permission_revoked', 'finished_at' => now()]);

            return;
        }
        if (blank($run->company->workos_organization_id)) {
            $run->update(['status' => WorkosOperationStatus::Failed, 'reason' => 'provider_not_configured', 'finished_at' => now()]);

            return;
        }
        $run->update(['status' => WorkosOperationStatus::Running, 'started_at' => now(), 'total' => User::query()->count()]);
        User::query()->chunkById(100, function ($people) use ($run) {
            foreach ($people as $person) {
                if (! $this->authorizedActor($run->company_id, $run->actor_id, Permission::PeopleSyncWorkos)) {
                    $run->update(['status' => WorkosOperationStatus::Failed, 'reason' => 'permission_revoked', 'finished_at' => now()]);

                    return false;
                }
                $outcome = 'failed';
                try {
                    $outcome = $this->refresh($person, $run->actor_id, $run) ? 'succeeded' : 'skipped';
                } catch (\Throwable $error) {
                    if ($error->getMessage() === 'account_sync_pending') {
                        continue;
                    }
                    $this->markUnavailable($person);
                }
                if ($outcome === 'succeeded') {
                    continue;
                }
                DB::transaction(function () use ($run, $outcome) {
                    $locked = WorkosSyncRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                    $locked->increment('processed');
                    $locked->increment($outcome);
                });
            }
        });
        $run->refresh();
        if ($run->status === WorkosOperationStatus::Failed) {
            return;
        }
        if ($run->processed < $run->total) {
            return;
        }
        DB::transaction(function () use ($run) {
            $run->update(['status' => ($run->failed > 0 || $run->skipped > 0) ? ($run->succeeded > 0 ? WorkosOperationStatus::PartialFailure : WorkosOperationStatus::Failed) : WorkosOperationStatus::Completed, 'finished_at' => now(), 'reason' => $run->failed > 0 ? 'provider_verification_failed' : ($run->skipped > 0 ? 'superseded' : null)]);
            AuditLog::query()->create(['company_id' => $run->company_id, 'actor_id' => $run->actor_id, 'action' => 'people.workos_synchronization_finished', 'metadata' => ['status' => $run->status->value, 'succeeded' => $run->succeeded, 'skipped' => $run->skipped, 'failed' => $run->failed]]);
        });
    }

    public function read(User $person): array
    {
        throw new RuntimeException('Use Account invitation synchronization.');
    }

    public function token(User $person): array
    {
        return [$person->company_id, $person->email, $person->workos_invitation_id, (int) $person->invitation_generation, Company::query()->whereKey($person->company_id)->value('workos_organization_id')];
    }

    public function refresh(User $person, ?int $actorId = null, ?WorkosSyncRun $run = null): bool
    {
        $this->readThroughAccount($person, $actorId, $run);
        if ($run) {
            DB::transaction(function () use ($run) {
                $locked = WorkosSyncRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                $locked->increment('processed');
                $locked->increment('succeeded');
            });
        }

        return true;
    }

    private function readThroughAccount(User $person, ?int $actorId, ?WorkosSyncRun $run): array
    {
        $actor = $this->authorizedActor($person->company_id, $actorId, Permission::PeopleSyncWorkos);
        abort_unless($actor && $actor->workos_user_id, 403);
        $link = app(AdoptPeopleIntoAccount::class)->ensure($actor, $person, 'person.invitation.reconcile');
        $person->refresh();
        $id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'compliance:'.$person->company_id.':reconcile:'.($run?->id ?? $person->invitation_generation).':'.$person->id);
        $payload = ['protocol_version' => 2, 'operation_id' => $id, 'kind' => 'person.invitation.reconcile', 'product' => 'compliance', 'account_company_uuid' => $link->account_company_uuid, 'workos_organization_id' => $person->company->workos_organization_id, 'subject_id' => $link->company_member_id, 'resource_sequence' => $person->invitation_generation + 1, 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => ['local_person_id' => $person->id, 'invitation_id' => $person->workos_invitation_id, 'generation' => $person->invitation_generation]];
        $operation = AccountRequestOperation::firstOrCreate(['id' => $id], ['company_id' => $person->company_id, 'user_id' => $person->id, 'actor_id' => $actor->id, 'kind' => 'person.invitation.reconcile', 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => $payload, 'payload_hash' => ApplyProductOperation::hash($payload)]);
        $client = app(AccountUserClient::class);
        $receipt = $operation->dispatched_at ? $client->receipt($operation->payload) : null;
        if (! $receipt) {
            abort_unless($this->authorizedActor($person->company_id, $actorId, Permission::PeopleSyncWorkos), 403);
            $operation->update(['dispatched_at' => now()]);
            $receipt = $client->submit($actor, $operation->payload);
        }
        $operation->update(['state' => $receipt['outcome'], 'receipt' => $receipt]);
        if ($receipt['outcome'] !== 'confirmed') {
            throw new RuntimeException('account_sync_pending');
        }
        $state = $receipt['confirmed_state'];
        $snapshot = $state['observation'] ?? null;
        abort_unless(is_array($snapshot), 409, 'observation_required');
        DB::transaction(function () use ($person, $payload, $snapshot) {
            $company = Company::whereKey($person->company_id)->lockForUpdate()->firstOrFail();
            app(ApplyInvitationObservation::class)->handle($company, [...$payload, 'payload' => $snapshot]);
        });

        return $state;
    }

    public function refreshPending(User $viewer): void
    {
        abort_unless($viewer->company_id === app(TenantContext::class)->id(), 403);
        $operations = AccountRequestOperation::where('company_id', $viewer->company_id)->where('actor_id', $viewer->id)->whereIn('kind', ['person.invitation.reconcile', 'person.invite'])->whereIn('state', ['pending', 'unknown'])->whereNotNull('dispatched_at')->limit(50)->get();
        foreach ($operations as $operation) {
            $permission = $operation->kind === 'person.invite' ? Permission::PeopleInvite : Permission::PeopleSyncWorkos;
            if (! $this->authorizedActor($viewer->company_id, $viewer->id, $permission)) {
                continue;
            }
            try {
                $receipt = app(AccountUserClient::class)->receipt($operation->payload);
                if ($receipt && in_array($receipt['outcome'], ['needs_attention', 'rejected', 'conflict'], true)) {
                    DB::transaction(function () use ($operation, $receipt) {
                        Company::whereKey($operation->company_id)->lockForUpdate()->firstOrFail();
                        $person = User::whereKey($operation->user_id)->lockForUpdate()->firstOrFail();
                        if ((int) $person->invitation_generation === (int) ($operation->payload['payload']['generation'] ?? -1) && $person->workos_invitation_id === ($operation->payload['payload']['invitation_id'] ?? null)) {
                            $person->forceFill(['invitation_check_error' => $receipt['safe_error'] ?? 'invitation_confirmation_unavailable'])->save();
                        }
                        $current = AccountRequestOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
                        if ($current->state === 'needs_attention') {
                            return;
                        }
                        $current->update(['state' => 'needs_attention', 'receipt' => $receipt, 'safe_error' => $receipt['safe_error'] ?? 'invitation_confirmation_unavailable']);
                        foreach (WorkosSyncRun::where('company_id', $operation->company_id)->where('actor_id', $operation->actor_id)->whereIn('status', ['queued', 'running'])->lockForUpdate()->get() as $run) {
                            $expected = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'compliance:'.$operation->company_id.':reconcile:'.$run->id.':'.$operation->user_id);
                            if ($expected !== $operation->id) {
                                continue;
                            }
                            $run->increment('processed');
                            $run->increment('failed');
                            $run->update(['reason' => $receipt['safe_error'] ?? 'invitation_confirmation_unavailable']);
                            if ($run->processed >= $run->total) {
                                $run->update(['status' => $run->succeeded > 0 ? WorkosOperationStatus::PartialFailure : WorkosOperationStatus::Failed, 'reason' => $receipt['safe_error'] ?? 'invitation_confirmation_unavailable', 'finished_at' => now()]);
                            }
                        }
                    });

                    continue;
                }
                if (! $receipt || $receipt['outcome'] !== 'confirmed') {
                    continue;
                }
                $snapshot = $receipt['confirmed_state']['observation'] ?? null;
                if (! is_array($snapshot)) {
                    continue;
                }
                DB::transaction(function () use ($operation, $receipt, $snapshot) {
                    $company = Company::whereKey($operation->company_id)->lockForUpdate()->firstOrFail();
                    User::whereKey($operation->user_id)->lockForUpdate()->firstOrFail();
                    $current = AccountRequestOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
                    if ($current->state === 'confirmed') {
                        return;
                    }
                    app(ApplyInvitationObservation::class)->handle($company, [...$current->payload, 'payload' => $snapshot]);
                    $current->update(['state' => 'confirmed', 'receipt' => $receipt, 'safe_error' => null]);
                    if ($operation->kind === 'person.invitation.reconcile') {
                        foreach (WorkosSyncRun::where('company_id', $company->id)->where('actor_id', $operation->actor_id)->whereIn('status', ['queued', 'running'])->lockForUpdate()->get() as $run) {
                            $expected = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'compliance:'.$company->id.':reconcile:'.$run->id.':'.$operation->user_id);
                            if ($expected !== $operation->id) {
                                continue;
                            }
                            $run->increment('processed');
                            $run->increment('succeeded');
                            if ($run->processed >= $run->total) {
                                $run->update(['status' => $run->failed > 0 ? WorkosOperationStatus::PartialFailure : WorkosOperationStatus::Completed, 'finished_at' => now()]);
                            }
                        }
                    } else {
                        foreach (WorkosInvitationAttempt::where('company_id', $company->id)->where('person_id', $operation->user_id)->where('actor_id', $operation->actor_id)->whereIn('status', ['queued', 'running', 'sending', 'delivery_unconfirmed'])->lockForUpdate()->get() as $attempt) {
                            $expected = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'compliance:'.$company->id.':invitation:'.$attempt->id);
                            if ($expected === $operation->id) {
                                $attempt->update(['status' => ($receipt['confirmed_state']['sent'] ?? false) ? WorkosOperationStatus::Succeeded : WorkosOperationStatus::Skipped, 'resulting_invitation_id' => $snapshot['invitation']['id'], 'finished_at' => now(), 'reason' => null]);
                            }
                        }
                    }
                });
            } catch (\Throwable) { /* Preserve the last verified projection on a reporting failure. */
            }
        }
    }

    public function markUnavailable(User $person): void
    {
        User::query()->whereKey($person->id)->where('invitation_generation', $person->invitation_generation)->update(['invitation_check_error' => 'provider_verification_failed']);
    }
}
