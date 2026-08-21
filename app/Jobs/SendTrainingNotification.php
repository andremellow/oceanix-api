<?php

namespace App\Jobs;

use App\Enums\ComplianceEventType;
use App\Mail\TrainingNotificationMail;
use App\Models\Company;
use App\Models\NotificationDelivery;
use App\Models\ScheduledNotification;
use App\Services\Compliance\ComplianceEventRecorder;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one scheduled reminder and records the outcome.
 *
 * Every attempt produces a delivery row, successful or not, because "we told them" is a
 * claim the organization has to be able to support. See docs/product-spec.md §16.
 */
class SendTrainingNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public readonly int $companyId;

    public function __construct(public readonly int $notificationId, ?int $companyId = null)
    {
        $this->companyId = $companyId ?? app(TenantContext::class)->id();
    }

    public function handle(ComplianceEventRecorder $events): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        app(TenantContext::class)->set($company);

        $notification = ScheduledNotification::query()
            ->with(['user', 'assignment.course'])
            ->find($this->notificationId);

        if ($notification === null || $notification->sent_at !== null) {
            return;
        }

        $delivery = NotificationDelivery::query()->create([
            'notification_id' => $notification->id,
            'channel' => 'mail',
            'status' => 'sending',
            'attempted_at' => now(),
        ]);

        try {
            Mail::to($notification->user->email)->send(new TrainingNotificationMail($notification));
        } catch (Throwable $e) {
            $delivery->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);

            $events->record(ComplianceEventType::NotificationFailed, $notification->user_id, [
                'assignment_id' => $notification->assignment_id,
                'metadata' => ['type' => $notification->type],
            ]);

            throw $e;
        }

        $notification->update(['sent_at' => now()]);
        $delivery->update(['status' => 'sent']);

        $events->record(ComplianceEventType::NotificationSent, $notification->user_id, [
            'assignment_id' => $notification->assignment_id,
            'metadata' => ['type' => $notification->type],
        ]);
    }
}
