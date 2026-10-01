<?php

namespace App\Jobs;

use App\Enums\WorkosOperationStatus;
use App\Models\Company;
use App\Models\WorkosInvitationAttempt;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SendWorkosInvitation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $companyId, public readonly int $personId, public readonly ?int $initiatedBy, public readonly ?int $attemptId = null) {}

    public function uniqueId(): string
    {
        return $this->companyId.':'.$this->personId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('workos-send:'.$this->companyId.':'.$this->personId))->shared()->expireAfter(120)];
    }

    public function handle(\App\Actions\People\SendWorkosInvitation $action): void
    {
        $context = app(TenantContext::class);
        $previous = $context->get();
        try {
            $context->set(Company::query()->findOrFail($this->companyId));
            $attempt = WorkosInvitationAttempt::query()->whereKey($this->attemptId)->where('person_id', $this->personId)->where('actor_id', $this->initiatedBy)->first();
            if ($attempt) {
                $action->execute($attempt);
            }
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $attempt = WorkosInvitationAttempt::withoutGlobalScope('company')->where('company_id', $this->companyId)->whereKey($this->attemptId)->first();
        if (! $attempt || ! $attempt->status->isOpen()) {
            return;
        }
        $sending = $attempt->send_started_at !== null;
        $attempt->update(['status' => $sending ? WorkosOperationStatus::DeliveryUnconfirmed : WorkosOperationStatus::Failed, 'reason' => $sending ? 'delivery_unconfirmed' : 'send_failed', 'finished_at' => now()]);
    }
}
