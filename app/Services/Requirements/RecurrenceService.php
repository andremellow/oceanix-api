<?php

namespace App\Services\Requirements;

use App\Enums\RenewalBasis;
use App\Models\TrainingRequirement;
use App\Models\UserTrainingAssignment;
use Illuminate\Support\Carbon;

/**
 * Works out when the next occurrence of a recurring obligation is due.
 *
 * `from_completion` starts the next cycle from when the person actually finished, so a late
 * completion pushes the next one out. `from_due_date` keeps the original calendar, so
 * finishing late does not shift the schedule. See docs/product-spec.md §10.
 */
class RecurrenceService
{
    public function nextDueAt(TrainingRequirement $requirement, UserTrainingAssignment $previous): ?Carbon
    {
        if (! $requirement->frequency_type->isRecurring()) {
            return null;
        }

        $anchor = match ($requirement->renewal_basis) {
            RenewalBasis::FromCompletion => $previous->completed_at ?? $previous->due_at,
            RenewalBasis::FromDueDate => $previous->due_at ?? $previous->completed_at,
        };

        if ($anchor === null) {
            return null;
        }

        return $requirement->frequency_type->advance($anchor, $requirement->frequency_value);
    }

    /** The occurrence becomes visible to the employee this many days before it is due. */
    public function availableAt(TrainingRequirement $requirement, Carbon $dueAt): Carbon
    {
        return $dueAt->copy()->subDays($requirement->assignment_lead_days);
    }

    public function firstDueAt(TrainingRequirement $requirement, ?Carbon $from = null): Carbon
    {
        return ($from ?? now())->copy()->addDays($requirement->due_days_after_assignment);
    }
}
