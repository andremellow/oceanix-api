<?php

namespace App\Actions\People;

use App\Enums\Permission;
use App\Enums\WorkosInvitationState;
use App\Enums\WorkosOperationStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkosInvitationAttempt;
use App\Services\Workos\WorkosInvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
            try {
                $this->reconcile->refresh($person, $attempt->actor_id);
            } catch (\Throwable) {
            }
            $this->finish($attempt, WorkosOperationStatus::DeliveryUnconfirmed, 'delivery_unconfirmed');

            return;
        }
        if (! $person->status->canAccessTenant()) {
            $this->finish($attempt, WorkosOperationStatus::Skipped, $person->status->value);

            return;
        }
        try {
            if (! $this->reconcile->refresh($person, $attempt->actor_id)) {
                $this->finish($attempt, WorkosOperationStatus::Skipped, 'superseded');

                return;
            }
        } catch (\Throwable) {
            $this->reconcile->markUnavailable($person);
            $this->finish($attempt, WorkosOperationStatus::Failed, 'provider_verification_failed');

            return;
        }
        $person->refresh();
        if ($person->workos_active_membership) {
            $this->finish($attempt, WorkosOperationStatus::Skipped, 'current_member');

            return;
        }
        if ($person->invitation_state === WorkosInvitationState::Accepted) {
            $this->finish($attempt, WorkosOperationStatus::Skipped, 'already_accepted');

            return;
        }
        if ($person->invitation_state === WorkosInvitationState::Unverified) {
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
            $snapshot = $person->invitation_state === WorkosInvitationState::Pending ? $this->workos->resend($person) : $this->workos->create($person);
            DB::transaction(function () use ($attempt, $person, $snapshot, $token) {
                Company::query()->whereKey($person->company_id)->lockForUpdate()->firstOrFail();
                $locked = User::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
                if ($this->reconcile->token($locked) !== $token) {
                    $this->finish($attempt, WorkosOperationStatus::DeliveryUnconfirmed, 'delivery_unconfirmed');

                    return;
                }
                $locked->forceFill($snapshot->attributes() + ['invitation_sent_at' => now(), 'invitation_verified_at' => now(), 'invitation_check_error' => null, 'invitation_generation' => $locked->invitation_generation + 1])->save();
                $attempt->update(['status' => WorkosOperationStatus::Succeeded, 'resulting_invitation_id' => $snapshot->id, 'finished_at' => now(), 'reason' => null]);
                AuditLog::query()->create(['company_id' => $attempt->company_id, 'actor_id' => $attempt->actor_id, 'action' => 'person.workos_invitation_sent', 'auditable_type' => User::class, 'auditable_id' => $person->id, 'after' => ['state' => $snapshot->state->value]]);
            });
        } catch (\Throwable $error) {
            $this->finish($attempt, $error->getMessage() === 'send_failed' ? WorkosOperationStatus::Failed : WorkosOperationStatus::DeliveryUnconfirmed, $error->getMessage() === 'send_failed' ? 'send_failed' : 'delivery_unconfirmed');
        }
    }

    private function finish(WorkosInvitationAttempt $attempt, WorkosOperationStatus $status, string $reason): void
    {
        DB::transaction(function () use ($attempt, $status, $reason) {
            $attempt->update(['status' => $status, 'reason' => $reason, 'finished_at' => now()]);
            AuditLog::query()->create(['company_id' => $attempt->company_id, 'actor_id' => $attempt->actor_id, 'action' => 'person.workos_invitation_outcome', 'auditable_type' => User::class, 'auditable_id' => $attempt->person_id, 'metadata' => ['status' => $status->value, 'reason' => $reason]]);
        });
    }
}
