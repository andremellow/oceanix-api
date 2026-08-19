<?php

use App\Enums\AssignmentStatus;
use App\Enums\ComplianceEventType;
use App\Enums\NotificationType;
use App\Jobs\SendTrainingNotification;
use App\Mail\TrainingNotificationMail;
use App\Models\ComplianceEvent;
use App\Models\NotificationDelivery;
use App\Models\ScheduledNotification;
use App\Services\Compliance\ComplianceEventRecorder;
use App\Services\Notifications\NotificationSchedulingService;
use Illuminate\Support\Facades\Mail;

it('announces a new assignment on the day it appears', function (): void {
    [$assignment] = trainableAssignment();
    $assignment->update(['assigned_at' => now(), 'due_at' => now()->addMonths(3)]);

    $types = app(NotificationSchedulingService::class)->typesDueFor($assignment->fresh());

    expect($types)->toContain(NotificationType::AssignmentCreated)
        ->not->toContain(NotificationType::DueSoon);
});

it('warns while the deadline is inside the due-soon window', function (): void {
    [$assignment] = trainableAssignment();
    $assignment->update(['assigned_at' => now()->subMonth(), 'due_at' => now()->addDays(5)]);

    expect(app(NotificationSchedulingService::class)->typesDueFor($assignment->fresh()))
        ->toContain(NotificationType::DueSoon);
});

it('announces the first day late, then repeats on a cadence instead of daily', function (): void {
    [$assignment] = trainableAssignment();
    $assignment->update(['assigned_at' => now()->subMonths(2), 'due_at' => now()]);
    $service = app(NotificationSchedulingService::class);

    expect($service->typesDueFor($assignment->fresh()))->toContain(NotificationType::Overdue);

    $fresh = $assignment->fresh();

    expect($service->typesDueFor($fresh, now()->addDays(3)))->toBe([])
        ->and($service->typesDueFor($fresh, now()->addDays(7)))->toContain(NotificationType::OverdueReminder)
        ->and($service->typesDueFor($fresh, now()->addDays(14)))->toContain(NotificationType::OverdueReminder);
});

it('says nothing about an assignment that is already satisfied', function (): void {
    [$assignment] = trainableAssignment();
    $assignment->update(['status' => AssignmentStatus::Completed, 'due_at' => now()->subMonth()]);

    expect(app(NotificationSchedulingService::class)->scheduleDue())->toBe(0)
        ->and(ScheduledNotification::query()->count())->toBe(0);
});

it('never schedules the same reminder twice in a day', function (): void {
    [$assignment] = trainableAssignment();
    $assignment->update(['assigned_at' => now()->subMonth(), 'due_at' => now()->addDays(3)]);
    $service = app(NotificationSchedulingService::class);

    $service->scheduleDue();
    $service->scheduleDue();

    expect(ScheduledNotification::query()->where('type', NotificationType::DueSoon->value)->count())->toBe(1)
        ->and(ComplianceEvent::query()->where('event_type', ComplianceEventType::NotificationQueued->value)->count())->toBe(1);
});

it('sends the reminder and records the delivery', function (): void {
    Mail::fake();
    [$assignment] = trainableAssignment();
    $notification = app(NotificationSchedulingService::class)
        ->schedule($assignment, NotificationType::DueSoon);

    app(SendTrainingNotification::class, ['notificationId' => $notification->id])
        ->handle(app(ComplianceEventRecorder::class));

    Mail::assertSent(TrainingNotificationMail::class);

    expect($notification->fresh()->sent_at)->not->toBeNull()
        ->and(NotificationDelivery::query()->where('status', 'sent')->count())->toBe(1)
        ->and(ComplianceEvent::query()->where('event_type', ComplianceEventType::NotificationSent->value)->count())->toBe(1);
});

it('records a failed delivery instead of losing it', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
    [$assignment] = trainableAssignment();
    $notification = app(NotificationSchedulingService::class)
        ->schedule($assignment, NotificationType::Overdue);

    expect(fn () => app(SendTrainingNotification::class, ['notificationId' => $notification->id])
        ->handle(app(ComplianceEventRecorder::class)))
        ->toThrow(RuntimeException::class);

    expect(NotificationDelivery::query()->where('status', 'failed')->count())->toBe(1)
        ->and($notification->fresh()->sent_at)->toBeNull()
        ->and(ComplianceEvent::query()->where('event_type', ComplianceEventType::NotificationFailed->value)->count())->toBe(1);
});

it('never sends the same notification twice', function (): void {
    Mail::fake();
    [$assignment] = trainableAssignment();
    $notification = app(NotificationSchedulingService::class)->schedule($assignment, NotificationType::DueSoon);
    $recorder = app(ComplianceEventRecorder::class);

    app(SendTrainingNotification::class, ['notificationId' => $notification->id])->handle($recorder);
    app(SendTrainingNotification::class, ['notificationId' => $notification->id])->handle($recorder);

    Mail::assertSentCount(1);
});
