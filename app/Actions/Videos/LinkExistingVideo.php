<?php

namespace App\Actions\Videos;

use App\Contracts\VideoProvider;
use App\Enums\ModuleVersionStatus;
use App\Enums\VideoStatus;
use App\Models\Account;
use App\Models\Lesson;
use App\Models\Video;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class LinkExistingVideo
{
    public function __construct(
        private readonly VideoProvider $provider,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Lesson $lesson, string $assetId, bool $allowAnyOwner = false, ?Account $platformActor = null): Video
    {
        if ($allowAnyOwner && $platformActor === null) {
            throw new LogicException('Only a platform administrator can link videos from any owner.');
        }
        if ($platformActor !== null) {
            $this->authorizePlatformActor($platformActor);
        }

        $asset = $this->provider->getAssetStatus($assetId);
        $ownerKey = $lesson->company_id === null ? 'platform' : 'company:'.$lesson->company_id;

        if ($asset->status !== VideoStatus::Ready
            || (! $allowAnyOwner && ($asset->metadata['oceanix_owner'] ?? null) !== $ownerKey)
            || ($asset->metadata['require_signed_urls'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'videoLibrary' => __('Only ready, private videos owned by this company can be linked.'),
            ]);
        }

        return DB::transaction(function () use ($lesson, $assetId, $asset, $platformActor): Video {
            $lesson = Lesson::query()->lockForUpdate()->findOrFail($lesson->id);
            $authorized = $platformActor === null ? null : $this->authorizePlatformActor($platformActor);
            if ($authorized !== null && ($lesson->company_id !== null || ! $lesson->is_shared || $lesson->status !== ModuleVersionStatus::Draft->value || $lesson->lineage_archived_at !== null)) {
                throw new LogicException('Videos can only be changed on platform-owned shared module drafts.');
            }

            Video::query()->where('lesson_id', $lesson->id)->update(['is_current' => false]);
            $generation = ((int) Video::query()->where('lesson_id', $lesson->id)->max('replacement_generation')) + 1;

            $video = Video::query()->create([
                'company_id' => $lesson->company_id,
                'lesson_id' => $lesson->id,
                'provider' => $this->provider->key(),
                'provider_asset_id' => $assetId,
                'provider_playback_id' => $asset->playbackId,
                'duration_seconds' => $asset->durationSeconds,
                'status' => $asset->status,
                'metadata' => $asset->metadata,
                'is_current' => true,
                'replacement_generation' => $generation,
            ]);

            $this->audit->log('lesson.video_linked', $lesson, after: [
                'provider' => $this->provider->key(),
                'asset_id' => $assetId,
            ], platformActor: $authorized);

            return $video;
        }, 3);
    }

    private function authorizePlatformActor(Account $actor): Account
    {
        $authorized = Account::query()->whereKey($actor->id)->where('is_platform_admin', true)->where('status', 'active')->first();
        if ($authorized === null) {
            throw new LogicException('Only an active platform administrator can change shared module videos.');
        }

        return $authorized;
    }
}
