<?php

namespace App\Console\Commands;

use App\Jobs\SendTrainingNotification;
use App\Models\ScheduledNotification;
use App\Services\Notifications\NotificationSchedulingService;
use Illuminate\Console\Command;

class DispatchTrainingNotifications extends Command
{
    protected $signature = 'oceanix:send-notifications';

    protected $description = 'Schedule the reminders due today and queue them for delivery';

    public function handle(NotificationSchedulingService $scheduler): int
    {
        $scheduled = $scheduler->scheduleDue();

        $pending = ScheduledNotification::query()
            ->whereNull('sent_at')
            ->whereDate('scheduled_for', '<=', now()->toDateString())
            ->pluck('id');

        $pending->each(fn (int $id) => SendTrainingNotification::dispatch($id));

        $this->info("Scheduled: {$scheduled}. Queued for delivery: {$pending->count()}.");

        return self::SUCCESS;
    }
}
