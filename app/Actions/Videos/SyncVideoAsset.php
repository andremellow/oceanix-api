<?php

namespace App\Actions\Videos;

use App\Contracts\VideoProvider;
use App\Enums\ModuleVersionStatus;
use App\Enums\VideoStatus;
use App\Exceptions\VideoProviderException;
use App\Models\Lesson;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
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
        if ($video->is_current && $video->status === VideoStatus::Ready && filled($video->metadata['hls'] ?? null)) {
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

        DB::transaction(function () use ($video, $status): void {
            $lessonId = $video->lesson_id;
            $lesson = Lesson::query()->lockForUpdate()->findOrFail($lessonId);
            $video = Video::query()->lockForUpdate()->where('lesson_id', $lessonId)->findOrFail($video->id);
            $video->update(array_filter([
                'status' => $status->status,
                'provider_playback_id' => $status->playbackId,
                'duration_seconds' => $status->durationSeconds,
                'metadata' => $status->metadata !== [] ? $status->metadata : null,
            ], fn ($value): bool => $value !== null));

            $isNewestCandidate = $video->replacement_generation === (int) Video::query()->where('lesson_id', $video->lesson_id)->max('replacement_generation');
            $isEditableSharedDraft = $lesson->company_id === null && $lesson->is_shared && $lesson->status === ModuleVersionStatus::Draft->value;

            if ($status->status === VideoStatus::Ready && $isNewestCandidate && $isEditableSharedDraft) {
                Video::query()->where('lesson_id', $video->lesson_id)->whereKeyNot($video->id)->update(['is_current' => false]);
                $video->update(['is_current' => true]);
            }
        }, 3);

        return $video->refresh();
    }
}
