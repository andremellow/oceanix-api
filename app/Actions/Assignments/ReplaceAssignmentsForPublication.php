<?php

namespace App\Actions\Assignments;

use App\Enums\AssignmentStatus;
use App\Enums\ComplianceEventType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ComplianceEvent;
use App\Models\CourseVersion;
use App\Models\ScheduledNotification;
use App\Models\SharedContentPropagation;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class ReplaceAssignmentsForPublication
{
    public function __construct(private readonly ComplianceEventRecorder $events) {}

    public function handle(
        CourseVersion $previous,
        CourseVersion $published,
        Account $actor,
        bool $restartInProgress = false,
        ?SharedContentPropagation $propagation = null,
    ): int {
        if (! $actor->is_platform_admin) {
            throw new \LogicException('A platform administrator account is required for publication replacement.');
        }

        $ids = UserTrainingAssignment::withoutGlobalScope('company')
            ->where('course_version_id', $previous->id)
            ->whereIn('status', array_column(AssignmentStatus::open(), 'value'))
            ->pluck('id');
        $replaced = 0;
        $originalCompany = app(TenantContext::class)->get();

        try {
            foreach ($ids as $id) {
                $replaced += DB::transaction(function () use ($id, $published, $actor, $restartInProgress, $propagation): int {
                    $assignment = UserTrainingAssignment::withoutGlobalScope('company')->lockForUpdate()->find($id);

                    if ($assignment === null || ! $assignment->status->isOpen()) {
                        return 0;
                    }

                    app(TenantContext::class)->set($assignment->company()->withoutGlobalScopes()->firstOrFail());
                    $hasStartEvidence = in_array($assignment->status, [AssignmentStatus::InProgress, AssignmentStatus::Failed], true)
                        || $assignment->courseAttempts()->exists()
                        || ComplianceEvent::query()->where('assignment_id', $assignment->id)
                            ->whereIn('event_type', [
                                ComplianceEventType::AssignmentOpened->value,
                                ComplianceEventType::CourseStarted->value,
                                ComplianceEventType::LessonStarted->value,
                            ])->exists();

                    if ($hasStartEvidence && ! $restartInProgress) {
                        return 0;
                    }

                    $existing = UserTrainingAssignment::withoutGlobalScope('company')
                        ->where('supersedes_assignment_id', $assignment->id)->first();
                    if ($existing !== null) {
                        return 0;
                    }

                    $reason = "Replaced by course version {$published->version_number}.";
                    $metadata = [
                        ...($assignment->metadata ?? []),
                        'replacement_reason' => $reason,
                        'platform_account_id' => $actor->id,
                    ];

                    $assignment->update([
                        'status' => AssignmentStatus::Cancelled,
                        'metadata' => $metadata,
                    ]);

                    $replacement = UserTrainingAssignment::query()->create([
                        'user_id' => $assignment->user_id,
                        'course_id' => $assignment->course_id,
                        'course_version_id' => $published->id,
                        'training_requirement_id' => $assignment->training_requirement_id,
                        'origin_type' => $assignment->origin_type,
                        'origin_id' => $assignment->origin_id,
                        'series_key' => $assignment->series_key,
                        'cycle_number' => $assignment->cycle_number,
                        'replacement_generation' => $assignment->replacement_generation + 1,
                        'assigned_at' => $assignment->assigned_at,
                        'available_at' => $assignment->available_at,
                        'due_at' => $assignment->due_at,
                        'expires_at' => $assignment->expires_at,
                        'status' => AssignmentStatus::Pending,
                        'supersedes_assignment_id' => $assignment->id,
                        'publication_course_version_id' => $published->id,
                        'propagation_id' => $propagation?->id,
                        'metadata' => $metadata,
                    ]);

                    ScheduledNotification::query()
                        ->where('assignment_id', $assignment->id)
                        ->whereNull('sent_at')
                        ->update(['assignment_id' => $replacement->id]);

                    $this->events->record(ComplianceEventType::AssignmentCancelled, $assignment->user_id, [
                        'assignment_id' => $assignment->id,
                        'course_version_id' => $assignment->course_version_id,
                        'metadata' => ['reason' => $reason, 'platform_account_id' => $actor->id],
                    ]);
                    $this->events->record(ComplianceEventType::AssignmentCreated, $replacement->user_id, [
                        'assignment_id' => $replacement->id,
                        'course_version_id' => $published->id,
                        'metadata' => ['supersedes_assignment_id' => $assignment->id, 'platform_account_id' => $actor->id],
                    ]);
                    AuditLog::query()->create([
                        'actor_id' => null,
                        'action' => 'assignment.replaced_for_publication',
                        'auditable_type' => $replacement::class,
                        'auditable_id' => $replacement->id,
                        'after' => ['previous_assignment_id' => $assignment->id, 'course_version_id' => $published->id],
                        'metadata' => ['platform_account_id' => $actor->id],
                    ]);

                    return 1;
                });
            }
        } finally {
            $originalCompany === null
                ? app(TenantContext::class)->clear()
                : app(TenantContext::class)->set($originalCompany);
        }

        return $replaced;
    }
}
