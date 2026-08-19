<?php

namespace App\Actions\Requirements;

use App\Enums\FrequencyType;
use App\Enums\RenewalBasis;
use App\Enums\RequirementStatus;
use App\Models\TrainingRequirement;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a training requirement.
 *
 * A requirement is a rule, not an obligation: editing one never rewrites assignments that
 * were already materialized from it. See docs/product-spec.md §8 and §9.
 */
class SaveTrainingRequirement
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, ?TrainingRequirement $requirement = null): TrainingRequirement
    {
        $frequency = FrequencyType::from((string) $attributes['frequency_type']);

        $payload = [
            'course_id' => (int) $attributes['course_id'],
            'name' => trim((string) $attributes['name']),
            'frequency_type' => $frequency,
            // A one-off requirement has no interval to store.
            'frequency_value' => $frequency->isRecurring() ? (int) $attributes['frequency_value'] : null,
            'renewal_basis' => RenewalBasis::from((string) $attributes['renewal_basis']),
            'assignment_lead_days' => (int) ($attributes['assignment_lead_days'] ?? 0),
            'due_days_after_assignment' => (int) $attributes['due_days_after_assignment'],
            'effective_from' => $attributes['effective_from'] ?: null,
            'effective_until' => $attributes['effective_until'] ?: null,
        ];

        return DB::transaction(function () use ($payload, $requirement): TrainingRequirement {
            if ($requirement === null) {
                $requirement = TrainingRequirement::query()->create($payload + [
                    'status' => RequirementStatus::Draft,
                    'created_by' => auth()->id(),
                ]);

                $this->audit->log('training_requirement.created', $requirement, after: $payload);

                return $requirement;
            }

            $before = $requirement->only(array_keys($payload));
            $requirement->update($payload);

            $this->audit->log('training_requirement.updated', $requirement, before: $before, after: $payload);

            return $requirement->refresh();
        });
    }
}
