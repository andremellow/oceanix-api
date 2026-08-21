<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsForEachCompany;
use App\Enums\AssignmentStatus;
use App\Models\UserTrainingAssignment;
use Illuminate\Console\Command;

/**
 * Moves open assignments past their deadline into the overdue status.
 *
 * The dashboards already derive lateness from dates, so this only materializes the status
 * that reporting and notifications key off.
 */
class UpdateOverdueAssignments extends Command
{
    use RunsForEachCompany;

    protected $signature = 'oceanix:update-overdue';

    protected $description = 'Mark open assignments whose deadline has passed as overdue';

    public function handle(): int
    {
        $this->forEachCompany(function (): void {
            $updated = UserTrainingAssignment::query()
                ->whereIn('status', [AssignmentStatus::Pending->value, AssignmentStatus::InProgress->value])
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->update(['status' => AssignmentStatus::Overdue->value]);

            $this->info("Assignments marked overdue: {$updated}.");
        });

        return self::SUCCESS;
    }
}
