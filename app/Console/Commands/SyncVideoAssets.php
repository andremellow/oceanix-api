<?php

namespace App\Console\Commands;

use App\Actions\Videos\SyncVideoAsset;
use App\Console\Commands\Concerns\RunsForEachCompany;
use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Console\Command;

/**
 * Encoding finishes on the provider's schedule, not while an editor tab is open, so a video
 * left in `processing` needs reconciling out of band or a version can never be published.
 */
class SyncVideoAssets extends Command
{
    use RunsForEachCompany;

    protected $signature = 'oceanix:sync-videos';

    protected $description = 'Reconcile videos still encoding with the video provider';

    public function handle(SyncVideoAsset $action): int
    {
        $this->forEachCompany(function ($company) use ($action): void {
            $this->sync(Video::query()->where('company_id', $company->id), $action);
        });

        $this->sync(Video::query()->whereNull('company_id'), $action);

        return self::SUCCESS;
    }

    private function sync($query, SyncVideoAsset $action): void
    {
        $pending = $query->clone()
            ->whereIn('status', [VideoStatus::Uploading->value, VideoStatus::Processing->value])
            ->get();

        foreach ($pending as $video) {
            $action->handle($video);
        }

        $cutoff = now()->subMinutes((int) config('oceanix.video_upload_expiry_minutes', 120));
        $query->clone()->where('status', VideoStatus::Uploading->value)->where(function ($query) use ($cutoff): void {
            $query->where('created_at', '<=', $cutoff)
                ->orWhereRaw('replacement_generation < (SELECT MAX(v2.replacement_generation) FROM videos v2 WHERE v2.lesson_id = videos.lesson_id)');
        })->update(['status' => VideoStatus::Failed]);

        $this->info("Videos reconciled: {$pending->count()}.");
    }
}
