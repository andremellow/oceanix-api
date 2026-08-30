<?php

namespace App\Actions\Videos;

use App\Contracts\VideoProvider;
use App\Enums\VideoStatus;
use App\Models\Lesson;
use App\Models\Video;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkExistingVideo
{
    public function __construct(
        private readonly VideoProvider $provider,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Lesson $lesson, string $assetId, bool $allowAnyOwner = false): Video
    {
        $asset = $this->provider->getAssetStatus($assetId);
        $ownerKey = $lesson->company_id === null ? 'platform' : 'company:'.$lesson->company_id;

        if ($asset->status !== VideoStatus::Ready
            || (! $allowAnyOwner && ($asset->metadata['oceanix_owner'] ?? null) !== $ownerKey)
            || ($asset->metadata['require_signed_urls'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'videoLibrary' => __('Only ready, private videos owned by this company can be linked.'),
            ]);
        }

        return DB::transaction(function () use ($lesson, $assetId, $asset): Video {
            $lesson->video?->delete();

            $video = Video::query()->create([
                'lesson_id' => $lesson->id,
                'provider' => $this->provider->key(),
                'provider_asset_id' => $assetId,
                'provider_playback_id' => $asset->playbackId,
                'duration_seconds' => $asset->durationSeconds,
                'status' => $asset->status,
                'metadata' => $asset->metadata,
            ]);

            $this->audit->log('lesson.video_linked', $lesson, after: [
                'provider' => $this->provider->key(),
                'asset_id' => $assetId,
            ]);

            return $video;
        });
    }
}
