<?php

namespace App\Jobs;

use App\Enums\WorkosOperationStatus;
use App\Models\Company;
use App\Models\WorkosSyncRun;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ReconcileWorkosInvitations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $companyId, public readonly ?int $actorId, public readonly int $runId) {}

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('workos-sync:'.$this->companyId))->shared()->expireAfter(3600)];
    }

    public function handle(\App\Actions\People\ReconcileWorkosInvitations $action): void
    {
        $context = app(TenantContext::class);
        $previous = $context->get();
        try {
            $company = Company::query()->findOrFail($this->companyId);
            $context->set($company);
            $run = WorkosSyncRun::query()->whereKey($this->runId)->where('actor_id', $this->actorId)->firstOrFail();
            $action->handle($run);
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    public function failed(\Throwable $error): void
    {
        WorkosSyncRun::withoutGlobalScope('company')->where('company_id', $this->companyId)->whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update(['status' => WorkosOperationStatus::Failed, 'reason' => 'synchronization_failed', 'finished_at' => now()]);
    }
}
