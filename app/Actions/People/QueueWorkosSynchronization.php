<?php

namespace App\Actions\People;

use App\Enums\Permission;
use App\Enums\WorkosOperationStatus;
use App\Jobs\ReconcileWorkosInvitations;
use App\Models\Company;
use App\Models\WorkosSyncRun;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class QueueWorkosSynchronization
{
    public function handle(): WorkosSyncRun
    {
        Gate::authorize(Permission::PeopleSyncWorkos->value);
        $company = app(TenantContext::class)->get();
        abort_unless($company, 403);
        abort_unless(auth()->user()->company_id === $company->id, 403);

        return DB::transaction(function () use ($company) {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $open = WorkosSyncRun::query()->whereIn('status', ['queued', 'running'])->first();
            if ($open) {
                return $open;
            }
            $run = WorkosSyncRun::query()->create(['actor_id' => auth()->id(), 'status' => WorkosOperationStatus::Queued]);
            DB::afterCommit(function () use ($company, $run) {
                try {
                    ReconcileWorkosInvitations::dispatch($company->id, $run->actor_id, $run->id);
                } catch (\Throwable) {
                    $run->update(['status' => WorkosOperationStatus::Failed, 'reason' => 'queue_unavailable', 'finished_at' => now()]);
                }
            });

            return $run;
        });
    }
}
