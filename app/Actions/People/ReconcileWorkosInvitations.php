<?php

namespace App\Actions\People;

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Data\WorkosInvitationSnapshot;
use App\Enums\Permission;
use App\Enums\WorkosInvitationState;
use App\Enums\WorkosOperationStatus;
use App\Models\AccountRequestOperation;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PersonAccountLink;
use App\Models\User;
use App\Models\WorkosSyncRun;
use App\Services\Account\AccountUserClient;
use App\Services\Workos\WorkosInvitationService;
use App\Services\Workos\WorkosOrganizationMembershipService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class ReconcileWorkosInvitations
{
    public function __construct(private readonly WorkosInvitationService $invitations, private readonly WorkosOrganizationMembershipService $memberships) {}

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
                } catch (\Throwable) {
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
        DB::transaction(function () use ($run) {
            $run->update(['status' => ($run->failed > 0 || $run->skipped > 0) ? ($run->succeeded > 0 ? WorkosOperationStatus::PartialFailure : WorkosOperationStatus::Failed) : WorkosOperationStatus::Completed, 'finished_at' => now(), 'reason' => $run->failed > 0 ? 'provider_verification_failed' : ($run->skipped > 0 ? 'superseded' : null)]);
            AuditLog::query()->create(['company_id' => $run->company_id, 'actor_id' => $run->actor_id, 'action' => 'people.workos_synchronization_finished', 'metadata' => ['status' => $run->status->value, 'succeeded' => $run->succeeded, 'skipped' => $run->skipped, 'failed' => $run->failed]]);
        });
    }

    public function read(User $person): array
    {
        abort_unless($person->company_id === app(TenantContext::class)->id(), 403);
        $org = Company::query()->whereKey($person->company_id)->value('workos_organization_id');
        if (blank($org)) {
            throw new RuntimeException('provider_not_configured');
        }
        $email = strtolower(trim($person->email));
        $stored = null;
        $storedUnavailable = false;
        if (filled($person->workos_invitation_id)) {
            $data = $this->invitations->get($person->workos_invitation_id);
            if ($data !== null) {
                try {
                    $stored = WorkosInvitationSnapshot::from($data, $email, $org);
                    if ($stored->id !== $person->workos_invitation_id) {
                        throw new RuntimeException;
                    }
                } catch (\Throwable) {
                    $stored = null;
                    $storedUnavailable = true;
                }
            } else {
                $storedUnavailable = true;
            }
        }
        $acceptedUserIds = [];
        if ($stored?->acceptedUserId) {
            $acceptedUserIds[$stored->acceptedUserId] = true;
        }
        $current = $stored;
        $acceptedHistory = $stored?->acceptedAt;
        $after = null;
        $seen = [];
        do {
            $page = $this->invitations->listPage($org, $email, $after);
            foreach ($page['data'] as $data) {
                // Provider filters are advisory: enforce organization and normalized exact email locally.
                if (! is_array($data) || ! is_string($data['organization_id'] ?? null) || ! is_string($data['email'] ?? null)) {
                    throw new RuntimeException('provider_verification_failed');
                }
                $candidate = WorkosInvitationSnapshot::from($data, $data['email'], $data['organization_id']);
                if ($data['organization_id'] !== $org || strtolower(trim($data['email'])) !== $email) {
                    continue;
                }
                if ($candidate->acceptedUserId) {
                    $acceptedUserIds[$candidate->acceptedUserId] = true;
                }
                if ($candidate->state === WorkosInvitationState::Accepted && $candidate->acceptedAt && (! $acceptedHistory || $candidate->acceptedAt->greaterThan($acceptedHistory))) {
                    $acceptedHistory = $candidate->acceptedAt;
                }
                if (! $stored && (! $current || [$candidate->createdAt->getTimestamp(), $candidate->id] > [$current->createdAt->getTimestamp(), $current->id])) {
                    $current = $candidate;
                }
            }
            $after = $page['list_metadata']['after'] ?? null;
            if ($after !== null && (! is_string($after) || isset($seen[$after]))) {
                throw new RuntimeException('provider_verification_failed');
            }
            if ($after !== null) {
                $seen[$after] = true;
            }
        } while ($after !== null && $after !== '');
        if ($current?->acceptedUserId) {
            $acceptedUserIds[$current->acceptedUserId] = true;
        }
        $userId = $person->workos_user_id ?: $person->account?->workos_user_id;
        $external = null;
        $active = false;
        if (filled($userId)) {
            $remote = $this->invitations->user($userId, $email);
            $acceptedUserIds[$userId] = true;
            if (filled($remote['last_sign_in_at'] ?? null)) {
                try {
                    $external = CarbonImmutable::parse($remote['last_sign_in_at'])->utc();
                } catch (\Throwable) {
                    throw new RuntimeException('provider_verification_failed');
                }
            }
        }

        foreach (array_keys($acceptedUserIds) as $acceptedUserId) {
            if ($acceptedUserId !== $userId) {
                $this->invitations->user($acceptedUserId, $email);
            }
            $member = $this->memberships->activeMembership($acceptedUserId, $org);
            $active = $active || $member;
        }

        return ['snapshot' => $current, 'history' => $acceptedHistory, 'membership' => $active, 'external' => $external, 'unavailable' => $storedUnavailable && ! $current];
    }

    public function token(User $person): array
    {
        return [$person->company_id, $person->email, $person->workos_invitation_id, (int) $person->invitation_generation, Company::query()->whereKey($person->company_id)->value('workos_organization_id')];
    }

    public function refresh(User $person, ?int $actorId = null, ?WorkosSyncRun $run = null): bool
    {
        $token = $this->token($person);
        $read = PersonAccountLink::where('company_id', $person->company_id)->where('user_id', $person->id)->exists()
            ? $this->readThroughAccount($person, $actorId, $run)
            : $this->read($person);

        return DB::transaction(function () use ($person, $token, $read, $actorId, $run) {
            Company::query()->whereKey($person->company_id)->lockForUpdate()->firstOrFail();
            $locked = User::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
            if ($this->token($locked) !== $token) {
                return false;
            }
            $attributes = $read['snapshot']?->attributes() ?? ['invitation_state' => $read['unavailable'] ? WorkosInvitationState::Unverified : WorkosInvitationState::NotInvited];
            $history = $locked->invitation_accepted_history_at;
            $new = $read['history'];
            if ($new && (! $history || $new->greaterThan($history))) {
                $history = $new;
            }
            $locked->forceFill($attributes + ['invitation_accepted_history_at' => $history, 'workos_active_membership' => $read['membership'], 'workos_last_sign_in_at' => $read['external'], 'invitation_verified_at' => now(), 'invitation_check_error' => $read['unavailable'] ? 'invitation_unavailable' : null, 'invitation_generation' => $read['generation'] ?? ($locked->invitation_generation + 1)])->save();
            AuditLog::query()->create(['company_id' => $locked->company_id, 'actor_id' => $actorId, 'action' => 'person.workos_invitation_reconciled', 'auditable_type' => User::class, 'auditable_id' => $locked->id, 'after' => ['state' => $locked->invitation_state->value, 'active_membership' => $locked->workos_active_membership]]);

            if ($run !== null) {
                $operation = WorkosSyncRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                $operation->increment('processed');
                $operation->increment('succeeded');
            }

            return true;
        });
    }

    private function readThroughAccount(User $person, ?int $actorId, ?WorkosSyncRun $run): array
    {
        $actor = $this->authorizedActor($person->company_id, $actorId, Permission::PeopleSyncWorkos);
        abort_unless($actor && $actor->workos_user_id, 403);
        $link = PersonAccountLink::where('company_id', $person->company_id)->where('user_id', $person->id)->sole();
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
            throw new RuntimeException('provider_verification_failed');
        }
        $state = $receipt['confirmed_state'];
        $invitation = $state['invitation'] ?? null;
        $snapshot = ! empty($invitation['id']) ? WorkosInvitationSnapshot::from($invitation, $person->email, $person->company->workos_organization_id) : null;

        abort_unless(is_int($state['generation'] ?? null) && $state['generation'] >= 0, 409);

        return ['generation' => $state['generation'], 'snapshot' => $snapshot, 'history' => empty($state['accepted_history_at']) ? null : CarbonImmutable::parse($state['accepted_history_at']), 'membership' => (bool) ($state['active_membership'] ?? false), 'external' => empty($state['external_last_sign_in_at']) ? null : CarbonImmutable::parse($state['external_last_sign_in_at']), 'unavailable' => (bool) ($state['unavailable'] ?? false)];
    }

    public function markUnavailable(User $person): void
    {
        User::query()->whereKey($person->id)->where('invitation_generation', $person->invitation_generation)->update(['invitation_check_error' => 'provider_verification_failed']);
    }
}
