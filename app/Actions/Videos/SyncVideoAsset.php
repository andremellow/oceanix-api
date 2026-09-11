<?php

namespace App\Actions\Videos;

use App\Actions\Videos\Concerns\LocksEditorMediaTarget;
use App\Contracts\VideoProvider;
use App\Enums\ModuleVersionStatus;
use App\Enums\VideoStatus;
use App\Exceptions\VideoProviderException;
use App\Models\Account;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Video;
use App\Services\CourseEditor\EditorRevision;
use Closure;
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
    use LocksEditorMediaTarget;

    public function __construct(
        private readonly VideoProvider $videoProvider,
        private readonly EditorRevision $revisions,
    ) {}

    public function forCompanyEditor(Video $video, User $actor, string $expectedRevision): Video
    {
        $this->revisions->assertExpected($expectedRevision);

        return $this->reconcile(
            $video,
            fn (Video $requested): Video => $this->lockEditorVideo($requested, $actor, null, $expectedRevision),
            true,
        );
    }

    public function forPlatformEditor(Video $video, Account $actor, string $expectedRevision): Video
    {
        $this->revisions->assertExpected($expectedRevision);

        return $this->reconcile(
            $video,
            fn (Video $requested): Video => $this->lockEditorVideo($requested, null, $actor, $expectedRevision),
            true,
        );
    }

    public function reconcileScheduled(Video $video): Video
    {
        return $this->reconcile($video, fn (Video $requested): Video => $this->lockScheduledVideo($requested), false);
    }

    /** @param Closure(Video): Video $lock */
    private function reconcile(Video $requested, Closure $lock, bool $failOnProviderError): Video
    {
        // The editor boundary re-authorizes and locks the exact lesson/video before even a
        // Ready fast return or provider read. Scheduled reconciliation uses its own bounded lock.
        $video = DB::transaction(fn (): Video => $lock($requested), 3);

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

            if ($failOnProviderError) {
                throw $e;
            }

            return $video;
        }

        $video = DB::transaction(function () use ($video, $status, $lock): Video {
            // Re-authorize after the provider round trip so revocation, lifecycle changes, and
            // concurrent edits cannot be hidden by an authorization performed before I/O.
            $video = $lock($video);
            $lesson = Lesson::query()->lockForUpdate()->findOrFail($video->lesson_id);
            $video->update(array_filter([
                'status' => $status->status,
                'provider_playback_id' => $status->playbackId,
                'duration_seconds' => $status->durationSeconds,
                'metadata' => $status->metadata !== [] ? $status->metadata : null,
            ], fn ($value): bool => $value !== null));

            $isNewestCandidate = $video->replacement_generation === (int) Video::query()->where('lesson_id', $video->lesson_id)->max('replacement_generation');
            $isEditableSharedDraft = $lesson->company_id === null && $lesson->is_shared && $lesson->getRawOriginal('status') === ModuleVersionStatus::Draft->value;
            $isEditableCompanyDraft = $lesson->course_version_id !== null
                && $lesson->courseVersion()->where('status', 'draft')->exists();

            if ($status->status === VideoStatus::Ready && $isNewestCandidate && ($isEditableSharedDraft || $isEditableCompanyDraft)) {
                Video::query()->where('lesson_id', $video->lesson_id)->whereKeyNot($video->id)->update(['is_current' => false]);
                $video->update(['is_current' => true]);
            }

            return $video->refresh();
        }, 3);

        return $video;
    }

    private function lockEditorVideo(Video $requested, ?User $actor, ?Account $platformActor, string $expectedRevision): Video
    {
        $lesson = Lesson::query()->findOrFail($requested->lesson_id);
        $target = $this->lockEditorMediaTarget($lesson, $actor, $platformActor, $expectedRevision, $this->revisions);

        return Video::query()
            ->where('lesson_id', $target['lesson']->id)
            ->lockForUpdate()
            ->findOrFail($requested->id);
    }

    private function lockScheduledVideo(Video $requested): Video
    {
        $lesson = Lesson::query()->lockForUpdate()->findOrFail($requested->lesson_id);

        return Video::query()->where('lesson_id', $lesson->id)->lockForUpdate()->findOrFail($requested->id);
    }
}
