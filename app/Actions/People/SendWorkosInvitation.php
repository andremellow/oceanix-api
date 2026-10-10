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
use App\Models\WorkosInvitationAttempt;
use App\Services\Account\AccountUserClient;
use App\Services\Workos\WorkosInvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Ramsey\Uuid\Uuid;

class SendWorkosInvitation
{
    public function __construct(private readonly ReconcileWorkosInvitations $reconcile, private readonly WorkosInvitationService $workos) {}

    public function handle(User $person): User
    {
        Gate::authorize('invite', $person);
        app(QueueWorkosInvitations::class)->handle([$person->id]);

        return $person->fresh();
    }

    public function execute(WorkosInvitationAttempt $attempt): void
    {
        abort_unless($attempt->company_id === app(TenantContext::class)->id(), 403);
        $attempt->refresh();
        if (! $attempt->status->isOpen()) {
            return;
        }
        $person = User::query()->whereKey($attempt->person_id)->first();
        if (! $person) {
            $this->finish($attempt, WorkosOperationStatus::Skipped, 'invitation_unavailable');

            return;
        }
        if (! $this->reconcile->authorizedActor($attempt->company_id, $attempt->actor_id, Permission::PeopleInvite)) {
            $this->finish($attempt, $attempt->send_started_at ? WorkosOperationStatus::DeliveryUnconfirmed : WorkosOperationStatus::Skipped, 'permission_revoked');

            return;
        }
        if ($attempt->status === WorkosOperationStatus::Sending) {
            $this->recover($person, $attempt);

            return;
        }
        if (! $person->status->canAccessTenant()) {
            $this->finish($attempt, WorkosOperationStatus::Skipped, $person->status->value);

            return;
        }
        $linked = PersonAccountLink::where('company_id', $person->company_id)->where('user_id', $person->id)->exists();
        try {
            if (! $linked && ! $this->reconcile->refresh($person, $attempt->actor_id)) {
                $this->finish($attempt, WorkosOperationStatus::Skipped, 'superseded');

                return;
            }
        } catch (\Throwable) {
            $this->reconcile->markUnavailable($person);
            $this->finish($attempt, WorkosOperationStatus::Failed, 'provider_verification_failed');

            return;
        }
        $person->refresh();
        if (! $linked && $person->workos_active_membership) {
            $this->finish($attempt, WorkosOperationStatus::Skipped, 'current_member');

            return;
        }
        if (! $linked && $person->invitation_state === WorkosInvitationState::Accepted) {
            $this->finish($attempt, WorkosOperationStatus::Skipped, 'already_accepted');

            return;
        }
        if (! $linked && $person->invitation_state === WorkosInvitationState::Unverified) {
            $this->finish($attempt, WorkosOperationStatus::Skipped, 'invitation_unavailable');

            return;
        }
        if ($person->invitation_state === WorkosInvitationState::Revoked && $attempt->mode !== 'selected') {
            $this->finish($attempt, WorkosOperationStatus::Skipped, 'revoked_default');

            return;
        }
        $token = $this->reconcile->token($person);
        $reserved = DB::transaction(function () use ($attempt, $person, $token) {
            Company::query()->whereKey($person->company_id)->lockForUpdate()->firstOrFail();
            $locked = User::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
            $intent = WorkosInvitationAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if (! $this->reconcile->authorizedActor($attempt->company_id, $attempt->actor_id, Permission::PeopleInvite)) {
                $this->finish($intent, WorkosOperationStatus::Skipped, 'permission_revoked');

                return false;
            }
            if (! $locked->status->canAccessTenant() || $this->reconcile->token($locked) !== $token || ! in_array($intent->status, [WorkosOperationStatus::Queued, WorkosOperationStatus::Running], true)) {
                $this->finish($intent, WorkosOperationStatus::Skipped, 'superseded');

                return false;
            }
            $intent->update(['status' => WorkosOperationStatus::Sending, 'send_started_at' => now(), 'started_at' => now(), 'generation' => $locked->invitation_generation]);

            return true;
        });
        if (! $reserved) {
            return;
        }
        try {
            $delivery = $this->sendThroughAccount($person, $attempt);
            $snapshot = $delivery['snapshot'];
            DB::transaction(function () use ($attempt, $person, $snapshot, $token, $delivery) {
                Company::query()->whereKey($person->company_id)->lockForUpdate()->firstOrFail();
                $locked = User::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
                if ($this->reconcile->token($locked) !== $token) {
                    $this->finish($attempt, WorkosOperationStatus::DeliveryUnconfirmed, 'delivery_unconfirmed');

                    return;
                }
                $locked->forceFill(($snapshot?->attributes() ?? []) + ['invitation_sent_at' => $delivery['sent'] ? now() : $locked->invitation_sent_at, 'invitation_verified_at' => now(), 'invitation_check_error' => null, 'workos_active_membership' => $delivery['active_membership'], 'invitation_generation' => $locked->invitation_generation + ($delivery['sent'] ? 1 : 0)])->save();
                $attempt->update(['status' => $delivery['sent'] ? WorkosOperationStatus::Succeeded : WorkosOperationStatus::Skipped, 'resulting_invitation_id' => $snapshot?->id, 'finished_at' => now(), 'reason' => $delivery['sent'] ? null : ($delivery['active_membership'] ? 'current_member' : 'already_accepted')]);
                AuditLog::query()->create(['company_id' => $attempt->company_id, 'actor_id' => $attempt->actor_id, 'action' => $delivery['sent'] ? 'person.workos_invitation_sent' : 'person.workos_invitation_outcome', 'auditable_type' => User::class, 'auditable_id' => $person->id, 'after' => ['state' => $snapshot?->state->value]]);
            });
        } catch (\Throwable $error) {
            $this->finish($attempt, $error->getMessage() === 'send_failed' ? WorkosOperationStatus::Failed : WorkosOperationStatus::DeliveryUnconfirmed, $error->getMessage() === 'send_failed' ? 'send_failed' : 'delivery_unconfirmed');
        }
    }

    private function operationId(WorkosInvitationAttempt $attempt): string
    {
        // PR29 attempt IDs remain integers; the cross-product protocol requires a UUID.
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'compliance:'.$attempt->company_id.':invitation:'.$attempt->id);
    }

    private function recover(User $person, WorkosInvitationAttempt $attempt): void
    {
        $operation = AccountRequestOperation::whereKey($this->operationId($attempt))->where('company_id', $attempt->company_id)->where('user_id', $person->id)->where('actor_id', $attempt->actor_id)->first();
        if (! $operation) {
            // A pre-cutover PR29 Sending attempt has no central intent to replay.
            // Retain read-only provider evidence while never inventing send proof.
            try {
                $this->reconcile->refresh($person, $attempt->actor_id);
            } catch (\Throwable) {
                $this->reconcile->markUnavailable($person);
            }
            $this->finish($attempt, WorkosOperationStatus::DeliveryUnconfirmed, 'delivery_unconfirmed');

            return;
        }
        try {
            // Recovery only observes the immutable accepted intent. A missing receipt never sends.
            $receipt = app(AccountUserClient::class)->receipt($operation->payload);
            if (($receipt['outcome'] ?? null) !== 'confirmed' || ! is_array($receipt['confirmed_state']['invitation'] ?? null)) {
                throw new \RuntimeException('delivery_unconfirmed');
            }
            $sent = (bool) ($receipt['confirmed_state']['sent'] ?? false);
            $active = (bool) ($receipt['confirmed_state']['active_membership'] ?? false);
            $snapshot = empty($receipt['confirmed_state']['invitation']['id']) ? null : WorkosInvitationSnapshot::from($receipt['confirmed_state']['invitation'], $person->email, $person->company->workos_organization_id);
            if ($sent && ! $snapshot) {
                throw new \RuntimeException('delivery_unconfirmed');
            }
            DB::transaction(function () use ($person, $attempt, $operation, $receipt, $snapshot, $sent, $active) {
                Company::whereKey($person->company_id)->lockForUpdate()->firstOrFail();
                $locked = User::whereKey($person->id)->lockForUpdate()->firstOrFail();
                $intent = WorkosInvitationAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                if ($intent->status !== WorkosOperationStatus::Sending || ! $locked->status->canAccessTenant() || (int) $locked->invitation_generation !== (int) $intent->generation || $locked->workos_invitation_id !== ($operation->payload['payload']['invitation_id'] ?? null)) {
                    $this->finish($intent, WorkosOperationStatus::DeliveryUnconfirmed, 'superseded');

                    return;
                }
                $locked->forceFill(($snapshot?->attributes() ?? []) + ['invitation_sent_at' => $sent ? now() : $locked->invitation_sent_at, 'invitation_verified_at' => now(), 'invitation_check_error' => null, 'workos_active_membership' => $active, 'invitation_generation' => $locked->invitation_generation + ($sent ? 1 : 0)])->save();
                $operation->update(['state' => 'confirmed', 'receipt' => $receipt]);
                $intent->update(['status' => $sent ? WorkosOperationStatus::Succeeded : WorkosOperationStatus::Skipped, 'resulting_invitation_id' => $snapshot?->id, 'finished_at' => now(), 'reason' => $sent ? null : ($active ? 'current_member' : 'already_accepted')]);
                AuditLog::create(['company_id' => $intent->company_id, 'actor_id' => $intent->actor_id, 'action' => $sent ? 'person.workos_invitation_sent' : 'person.workos_invitation_outcome', 'auditable_type' => User::class, 'auditable_id' => $person->id, 'after' => ['state' => $snapshot?->state->value]]);
            });
        } catch (\Throwable) {
            $this->finish($attempt, WorkosOperationStatus::DeliveryUnconfirmed, 'delivery_unconfirmed');
        }
    }

    private function sendThroughAccount(User $person, WorkosInvitationAttempt $attempt): array
    {
        $actor = User::whereKey($attempt->actor_id)->firstOrFail();
        abort_unless($actor->workos_user_id, 403);
        $link = PersonAccountLink::where('company_id', $person->company_id)->where('user_id', $person->id)->firstOrFail();
        $operationId = $this->operationId($attempt);
        $payload = ['protocol_version' => 2, 'operation_id' => $operationId, 'kind' => 'person.invite', 'product' => 'compliance', 'account_company_uuid' => $link->account_company_uuid,
            'workos_organization_id' => $person->company->workos_organization_id, 'subject_id' => $link->company_member_id,
            'resource_sequence' => $person->invitation_generation + 1, 'actor_workos_user_id' => $actor->workos_user_id,
            'payload' => ['local_person_id' => $person->id, 'invitation_id' => $person->workos_invitation_id, 'generation' => $person->invitation_generation]];
        $operation = AccountRequestOperation::firstOrCreate(['id' => $operationId], ['company_id' => $person->company_id, 'user_id' => $person->id, 'actor_id' => $actor->id, 'kind' => 'person.invite', 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => $payload, 'payload_hash' => ApplyProductOperation::hash($payload)]);
        $client = app(AccountUserClient::class);
        $receipt = $operation->dispatched_at ? $client->receipt($operation->payload) : null;
        if (! $receipt) {
            Gate::forUser($actor)->authorize('invite', $person);
            $operation->update(['dispatched_at' => now()]);
            $receipt = $client->submit($actor, $operation->payload);
        }
        $operation->update(['state' => $receipt['outcome'], 'receipt' => $receipt]);
        if ($receipt['outcome'] !== 'confirmed' || ! is_array($receipt['confirmed_state']['invitation'] ?? null)) {
            throw new \RuntimeException('delivery_unconfirmed');
        }
        $state = $receipt['confirmed_state'];

        return ['snapshot' => empty($state['invitation']['id']) ? null : WorkosInvitationSnapshot::from($state['invitation'], $person->email, $person->company->workos_organization_id), 'sent' => (bool) ($state['sent'] ?? false), 'active_membership' => (bool) ($state['active_membership'] ?? false)];
    }

    private function finish(WorkosInvitationAttempt $attempt, WorkosOperationStatus $status, string $reason): void
    {
        DB::transaction(function () use ($attempt, $status, $reason) {
            $attempt->update(['status' => $status, 'reason' => $reason, 'finished_at' => now()]);
            AuditLog::query()->create(['company_id' => $attempt->company_id, 'actor_id' => $attempt->actor_id, 'action' => 'person.workos_invitation_outcome', 'auditable_type' => User::class, 'auditable_id' => $attempt->person_id, 'metadata' => ['status' => $status->value, 'reason' => $reason]]);
        });
    }
}
