<?php

namespace App\Actions\Videos;

use App\Contracts\VideoProvider;
use App\Enums\VideoStatus;
use App\Exceptions\VideoProviderException;
use App\Models\Video;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles a local video row with the provider's encoding state.
 *
 * Called while the editor polls a processing video, and later by a scheduled job so an
 * asset never stays stuck because a browser tab was closed.
 */
class SyncVideoAsset
{
    public function __construct(private readonly VideoProvider $videoProvider) {}

    public function handle(Video $video): Video
    {
        if ($video->status === VideoStatus::Ready) {
            return $video;
        }

        try {
            $status = $this->videoProvider->getAssetStatus($video->provider_asset_id);
        } catch (VideoProviderException $e) {
            // A provider outage must not flip a good asset to failed; leave the row alone
            // and let the next poll or the scheduled job retry.
            Log::warning('Video status sync failed', [
                'video_id' => $video->id,
                'message' => $e->getMessage(),
            ]);

            return $video;
        }

        $video->update(array_filter([
            'status' => $status->status,
            'provider_playback_id' => $status->playbackId,
            'duration_seconds' => $status->durationSeconds,
            'metadata' => $status->metadata !== [] ? $status->metadata : null,
        ], fn ($value): bool => $value !== null));

        return $video->refresh();
    }
}
