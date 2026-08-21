<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Workos\WorkosInvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWorkosInvitation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $companyId,
        public readonly int $personId,
        public readonly ?int $initiatedBy,
    ) {}

    public function uniqueId(): string
    {
        return $this->companyId.':'.$this->personId;
    }

    public function handle(WorkosInvitationService $workos): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        app(TenantContext::class)->set($company);
        $person = User::query()->find($this->personId);

        if ($person === null) {
            return;
        }

        $invitationId = $workos->send($person);
        $person->forceFill([
            'workos_invitation_id' => $invitationId,
            'invitation_sent_at' => now(),
        ])->save();

        AuditLog::query()->create([
            'actor_id' => $this->initiatedBy,
            'action' => 'person.workos_invitation_sent',
            'auditable_type' => User::class,
            'auditable_id' => $person->id,
            'after' => [
                'workos_invitation_id' => $invitationId,
                'organization_id' => $company->workos_organization_id,
            ],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        app(TenantContext::class)->set($company);
        AuditLog::query()->create([
            'actor_id' => $this->initiatedBy,
            'action' => 'person.workos_invitation_failed',
            'auditable_type' => User::class,
            'auditable_id' => $this->personId,
            'metadata' => ['error' => $exception->getMessage()],
        ]);
    }
}
