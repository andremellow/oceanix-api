<?php

namespace App\Console\Commands;

use App\Actions\Lgpd\AnonymizePerson;
use App\Console\Commands\Concerns\RunsForEachCompany;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Applies the retention policy to people who left.
 *
 * The window is deliberately long and configurable: the obligation to keep training evidence
 * usually outlives employment, and the right answer is a legal decision, not a default.
 * Nothing runs on a schedule — an operator triggers it knowingly.
 */
class AnonymizeTerminatedPeople extends Command
{
    use RunsForEachCompany;

    protected $signature = 'oceanix:anonymize-terminated {--months=} {--dry-run}';

    protected $description = 'Anonymize people terminated longer ago than the retention window, keeping their compliance evidence';

    public function handle(AnonymizePerson $action): int
    {
        $months = (int) ($this->option('months') ?? config('oceanix.retention.terminated_months', 60));
        $cutoff = now()->subMonths($months);

        $this->forEachCompany(function () use ($action, $cutoff): void {
            $people = User::query()
                ->where('status', UserStatus::Terminated->value)
                ->whereNotNull('terminated_at')
                ->where('terminated_at', '<', $cutoff)
                ->where('email', 'not like', '%@anonymized.invalid')
                ->get();

            $this->line(sprintf(
                '  %d people terminated before %s.',
                $people->count(),
                $cutoff->toDateString(),
            ));

            if ($this->option('dry-run')) {
                $people->each(fn (User $user) => $this->line("  would anonymize: {$user->email}"));

                return;
            }

            $people->each(fn (User $user) => $action->handle($user));
            $this->info("  Anonymized: {$people->count()}. Their training evidence was preserved.");
        });

        return self::SUCCESS;
    }
}
