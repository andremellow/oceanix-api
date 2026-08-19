<?php

namespace App\Console\Commands;

use App\Actions\Videos\SyncVideoAsset;
use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Console\Command;

/**
 * Encoding finishes on the provider's schedule, not while an editor tab is open, so a video
 * left in `processing` needs reconciling out of band or a version can never be published.
 */
class SyncVideoAssets extends Command
{
    protected $signature = 'oceanix:sync-videos';

    protected $description = 'Reconcile videos still encoding with the video provider';

    public function handle(SyncVideoAsset $action): int
    {
        $pending = Video::query()
            ->whereIn('status', [VideoStatus::Uploading->value, VideoStatus::Processing->value])
            ->get();

        foreach ($pending as $video) {
            $action->handle($video);
        }

        $this->info("Videos reconciled: {$pending->count()}.");

        return self::SUCCESS;
    }
}
