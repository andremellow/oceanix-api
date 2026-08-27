<?php

namespace App\Jobs;

use App\Actions\Courses\CreatePropagatedCourseVersion;
use App\Enums\SharedContentPropagationItemStatus;
use App\Enums\SharedContentPropagationStatus;
use App\Models\Company;
use App\Models\SharedContentPropagation;
use App\Models\SharedContentPropagationItem;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PropagateSharedModuleToCourse implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $itemId) {}

    public function handle(CreatePropagatedCourseVersion $createVersion): void
    {
        $item = SharedContentPropagationItem::query()->findOrFail($this->itemId);
        if ($item->status === SharedContentPropagationItemStatus::Succeeded) {
            return;
        }

        $original = app(TenantContext::class)->get();
        try {
            if ($item->company_id !== null) {
                app(TenantContext::class)->set(Company::query()->findOrFail($item->company_id));
            } else {
                app(TenantContext::class)->clear();
            }

            $item->update([
                'status' => SharedContentPropagationItemStatus::Processing,
                'attempt_count' => $item->attempt_count + 1,
                'started_at' => $item->started_at ?? now(),
                'last_error' => null,
            ]);
            $item->propagation->update([
                'status' => SharedContentPropagationStatus::Processing,
                'started_at' => $item->propagation->started_at ?? now(),
            ]);

            $createVersion->handle($item);
            $item->update(['status' => SharedContentPropagationItemStatus::Succeeded, 'completed_at' => now()]);
            $this->refreshAggregate($item->propagation);
        } catch (Throwable $exception) {
            $item->update([
                'status' => SharedContentPropagationItemStatus::Failed,
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                'completed_at' => now(),
            ]);
            $this->refreshAggregate($item->propagation);
            throw $exception;
        } finally {
            $original === null ? app(TenantContext::class)->clear() : app(TenantContext::class)->set($original);
        }
    }

    private function refreshAggregate(SharedContentPropagation $propagation): void
    {
        $processed = $propagation->items()->whereIn('status', [
            SharedContentPropagationItemStatus::Succeeded->value,
            SharedContentPropagationItemStatus::Failed->value,
        ])->count();
        $succeeded = $propagation->items()->where('status', SharedContentPropagationItemStatus::Succeeded->value)->count();
        $failed = $propagation->items()->where('status', SharedContentPropagationItemStatus::Failed->value)->count();
        $complete = $processed === $propagation->affected_count;

        $propagation->update([
            'processed_count' => $processed,
            'succeeded_count' => $succeeded,
            'failed_count' => $failed,
            'status' => $complete
                ? ($failed > 0 ? SharedContentPropagationStatus::CompletedWithFailures : SharedContentPropagationStatus::Completed)
                : SharedContentPropagationStatus::Processing,
            'completed_at' => $complete ? now() : null,
        ]);
    }
}
