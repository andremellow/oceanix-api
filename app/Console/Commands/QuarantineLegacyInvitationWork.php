<?php

namespace App\Console\Commands;

use App\Models\WorkosInvitationAttempt;
use App\Models\WorkosSyncRun;
use Illuminate\Console\Command;

class QuarantineLegacyInvitationWork extends Command
{
    protected $signature = 'account:quarantine-legacy-invitations {--apply : Retain and mark legacy operational work for review}';

    protected $description = 'Inspect or quarantine legacy invitation work without provider dispatch';

    public function handle(): int
    {
        $attempts = WorkosInvitationAttempt::withoutGlobalScope('company')->whereIn('status', ['queued', 'running', 'sending']);
        $runs = WorkosSyncRun::withoutGlobalScope('company')->whereIn('status', ['queued', 'running']);
        $this->line('Legacy invitation attempts: '.$attempts->count().'; synchronization runs: '.$runs->count());
        if ($this->option('apply')) {
            $attempts->update(['status' => 'delivery_unconfirmed', 'reason' => 'legacy_cutover_review', 'finished_at' => now()]);
            $runs->update(['status' => 'failed', 'reason' => 'legacy_cutover_review', 'finished_at' => now()]);
        }

        return self::SUCCESS;
    }
}
