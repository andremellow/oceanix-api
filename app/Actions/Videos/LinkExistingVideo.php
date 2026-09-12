<?php

namespace App\Actions\Videos;

use App\Actions\Videos\Concerns\LocksEditorMediaTarget;
use App\Contracts\VideoProvider;
use App\Enums\VideoStatus;
use App\Models\Account;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Video;
use App\Services\Audit\AuditLogger;
use App\Services\CourseEditor\EditorRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkExistingVideo
{
    use LocksEditorMediaTarget;

    public function __construct(
        private readonly VideoProvider $provider,
        private readonly AuditLogger $audit,
        private readonly EditorRevision $revisions,
    ) {}

    public function forCompanyEditor(Lesson $lesson, string $assetId, User $actor, string $expectedRevision): Video
    {
        return $this->link($lesson, $assetId, false, null, $actor, $expectedRevision);
    }

    public function forPlatformEditor(Lesson $lesson, string $assetId, Account $actor, string $expectedRevision): Video
    {
        return $this->link($lesson, $assetId, true, $actor, null, $expectedRevision);
    }

    private function link(Lesson $lesson, string $assetId, bool $allowAnyOwner, ?Account $platformActor, ?User $actor, string $expectedRevision): Video
    {
        $this->assertExpectedEditorMediaRevision($expectedRevision);
        $eligible = DB::transaction(
            fn (): Lesson => $this->lockEditorMediaTarget($lesson, $actor, $platformActor, $expectedRevision, $this->revisions)['lesson'],
            3,
        );

        $asset = $this->provider->getAssetStatus($assetId);
        $ownerKey = $eligible->company_id === null ? 'platform' : 'company:'.$eligible->company_id;

        if ($asset->status !== VideoStatus::Ready
            || (! $allowAnyOwner && ($asset->metadata['oceanix_owner'] ?? null) !== $ownerKey)
            || ($asset->metadata['require_signed_urls'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'videoLibrary' => __('Only ready, private videos owned by this company can be linked.'),
            ]);
        }

        return DB::transaction(function () use ($lesson, $assetId, $asset, $platformActor, $actor, $expectedRevision): Video {
            $target = $this->lockEditorMediaTarget($lesson, $actor, $platformActor, $expectedRevision, $this->revisions);
            $lesson = $target['lesson'];

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
            ], platformActor: $target['platform_actor']);

            return $video;
        }, 3);
    }
}
