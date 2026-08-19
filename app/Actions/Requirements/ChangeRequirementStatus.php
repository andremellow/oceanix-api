<?php

namespace App\Actions\Requirements;

use App\Enums\RequirementStatus;
use App\Exceptions\RequirementActivationException;
use App\Models\TrainingRequirement;
use App\Services\Audit\AuditLogger;

/**
 * Activating a rule is what turns it into real obligations, so it has its own permission
 * and its own guard rails: a rule with no audience, or pointing at a course with nothing
 * published, must never go live.
 */
class ChangeRequirementStatus
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(TrainingRequirement $requirement, RequirementStatus $status): TrainingRequirement
    {
        if ($status === RequirementStatus::Active) {
            if ($requirement->targets()->doesntExist()) {
                throw RequirementActivationException::withoutAudience();
            }

            if ($requirement->course?->current_published_version_id === null) {
                throw RequirementActivationException::withoutPublishedVersion();
            }
        }

        $before = $requirement->status;
        $requirement->update(['status' => $status]);

        $this->audit->log(
            'training_requirement.status_changed',
            $requirement,
            before: ['status' => $before->value],
            after: ['status' => $status->value],
        );

        return $requirement->refresh();
    }
}
