<?php

namespace App\Actions\Videos;

use App\Contracts\VideoProvider;
use App\Data\Video\VideoUpload;
use App\Enums\VideoStatus;
use App\Models\Account;
use App\Models\Lesson;
use App\Models\Video;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Opens a one-time upload slot at the provider.
 *
 * The file goes straight from the browser to the provider — it never transits this server,
 * so a 2 GB training video costs no application memory or request time. We only persist the
 * asset identifier the provider hands back.
 */
class RequestVideoUpload
{
    public function __construct(
        private readonly VideoProvider $videoProvider,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Lesson $lesson, int $maxDurationSeconds = 7200, ?Account $platformActor = null): VideoUpload
    {
        $upload = $this->videoProvider->createUpload(
            $lesson->title !== '' ? $lesson->title : __('Untitled lesson'),
            $maxDurationSeconds,
            $lesson->company_id === null ? 'platform' : 'company:'.$lesson->company_id,
        );

        DB::transaction(function () use ($lesson, $upload, $platformActor): void {
            $previous = $lesson->video;

            // Replacing a video only detaches it from this draft lesson. The asset stays at
            // the provider because a published version may still reference it.
            $previous?->delete();

            Video::query()->create([
                'lesson_id' => $lesson->id,
                'provider' => $upload->provider,
                'provider_asset_id' => $upload->assetId,
                'status' => VideoStatus::Uploading,
            ]);

            $this->audit->log('lesson.video_upload_requested', $lesson, after: [
                'provider' => $upload->provider,
                'asset_id' => $upload->assetId,
            ], platformActor: $platformActor);
        });

        return $upload;
    }
}
