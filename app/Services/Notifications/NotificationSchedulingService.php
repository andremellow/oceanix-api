<?php

namespace App\Services\Notifications;

use App\Enums\ComplianceEventType;
use App\Enums\NotificationType;
use App\Models\Account;
use App\Models\ScheduledNotification;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Decides which reminders an assignment owes today.
 *
 * Scheduling is idempotent on assignment + type + date, enforced by the database, so
 * running the job repeatedly cannot produce a second email for the same day. Reminders
 * stop once the obligation is satisfied. See docs/product-spec.md §16.
 */
class NotificationSchedulingService
{
    public function __construct(private readonly ComplianceEventRecorder $events) {}

    /** @return int number of notifications scheduled */
    public function scheduleDue(): int
    {
        $scheduled = 0;

        UserTrainingAssignment::query()
            ->open()
            ->with(['user', 'course'])
            ->each(function (UserTrainingAssignment $assignment) use (&$scheduled): void {
                foreach ($this->typesDueFor($assignment) as $type) {
                    if ($this->schedule($assignment, $type) !== null) {
                        $scheduled++;
                    }
                }
            });

        return $scheduled;
    }

    /**
     * @return list<NotificationType>
     */
    public function typesDueFor(UserTrainingAssignment $assignment, ?Carbon $on = null): array
    {
        $on ??= now();
        $types = [];

        // Announced once, on the day the obligation appears.
        if ($assignment->assigned_at->isSameDay($on)) {
            $types[] = NotificationType::AssignmentCreated;
        }

        if ($assignment->due_at === null) {
            return $types;
        }

        $daysUntilDue = (int) $on->startOfDay()->diffInDays($assignment->due_at->startOfDay(), absolute: false);

        if ($daysUntilDue > 0 && $daysUntilDue <= (int) config('oceanix.due_soon_days', 14)) {
            $types[] = NotificationType::DueSoon;
        }

        if ($daysUntilDue === 0 || $assignment->due_at->startOfDay()->lt($on->startOfDay())) {
            $daysOverdue = (int) $assignment->due_at->startOfDay()->diffInDays($on->startOfDay());

            // The first day it is late is announced; after that it repeats on a cadence
            // rather than every single day, which people learn to ignore.
            $types[] = $daysOverdue <= 0
                ? NotificationType::Overdue
                : ($daysOverdue % (int) config('oceanix.overdue_reminder_days', 7) === 0
                    ? NotificationType::OverdueReminder
                    : null);
        }

        return array_values(array_filter($types));
    }

    public function schedule(UserTrainingAssignment $assignment, NotificationType $type, ?Carbon $for = null, ?Account $platformActor = null): ?ScheduledNotification
    {
        $for ??= now();

        try {
            $notification = ScheduledNotification::query()->create([
                'user_id' => $assignment->user_id,
                'assignment_id' => $assignment->id,
                'type' => $type->value,
                'scheduled_for' => $for->toDateString(),
                'payload' => [
                    'course' => $assignment->course->title,
                    'due_at' => $assignment->due_at?->toDateString(),
                    ...($platformActor === null ? [] : ['platform_account_id' => $platformActor->id]),
                ],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already scheduled for this day: that is the guarantee working.
            return null;
        }

        $this->events->record(ComplianceEventType::NotificationQueued, $assignment->user_id, [
            'assignment_id' => $assignment->id,
            'metadata' => ['type' => $type->value],
        ], $platformActor);

        return $notification;
    }
}
