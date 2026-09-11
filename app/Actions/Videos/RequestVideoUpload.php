<?php

namespace App\Actions\Videos;

use App\Actions\Videos\Concerns\LocksEditorMediaTarget;
use App\Contracts\VideoProvider;
use App\Data\Video\VideoUpload;
use App\Enums\VideoStatus;
use App\Models\Account;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Video;
use App\Services\Audit\AuditLogger;
use App\Services\CourseEditor\EditorRevision;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Opens a one-time upload slot at the provider.
 *
 * The file goes straight from the browser to the provider — it never transits this server,
 * so a 2 GB training video costs no application memory or request time. We only persist the
 * asset identifier the provider hands back.
 */
class RequestVideoUpload
{
    use LocksEditorMediaTarget;

    public function __construct(
        private readonly VideoProvider $videoProvider,
        private readonly AuditLogger $audit,
        private readonly EditorRevision $revisions,
    ) {}

    public function forCompanyEditor(Lesson $lesson, User $actor, string $expectedRevision, int $maxDurationSeconds = 7200): VideoUpload
    {
        return $this->request($lesson, $maxDurationSeconds, null, $actor, $expectedRevision);
    }

    public function forPlatformEditor(Lesson $lesson, Account $actor, string $expectedRevision, int $maxDurationSeconds = 7200): VideoUpload
    {
        return $this->request($lesson, $maxDurationSeconds, $actor, null, $expectedRevision);
    }

    private function request(Lesson $lesson, int $maxDurationSeconds, ?Account $platformActor, ?User $actor, string $expectedRevision): VideoUpload
    {
        $this->assertExpectedEditorMediaRevision($expectedRevision);
        $eligible = DB::transaction(
            fn (): Lesson => $this->lockEditorMediaTarget($lesson, $actor, $platformActor, $expectedRevision, $this->revisions)['lesson'],
            3,
        );

        $upload = $this->videoProvider->createUpload(
            $eligible->title !== '' ? $eligible->title : __('Untitled lesson'),
            $maxDurationSeconds,
            $eligible->company_id === null ? 'platform' : 'company:'.$eligible->company_id,
        );

        try {
            $video = DB::transaction(function () use ($lesson, $upload, $platformActor, $actor, $expectedRevision): Video {
                $target = $this->lockEditorMediaTarget($lesson, $actor, $platformActor, $expectedRevision, $this->revisions);
                $lesson = $target['lesson'];

                $generation = ((int) Video::query()->where('lesson_id', $lesson->id)->max('replacement_generation')) + 1;
                $hasCurrent = Video::query()->where('lesson_id', $lesson->id)->where('is_current', true)->exists();

                $video = Video::query()->create([
                    'company_id' => $lesson->company_id,
                    'lesson_id' => $lesson->id,
                    'provider' => $upload->provider,
                    'provider_asset_id' => $upload->assetId,
                    'status' => VideoStatus::Uploading,
                    'is_current' => ! $hasCurrent,
                    'replacement_generation' => $generation,
                ]);

                $this->audit->log('lesson.video_upload_requested', $lesson, after: [
                    'provider' => $upload->provider,
                    'asset_id' => $upload->assetId,
                ], platformActor: $target['platform_actor']);

                return $video;
            }, 3);
        } catch (Throwable $exception) {
            try {
                $this->videoProvider->deleteAsset($upload->assetId);
            } catch (Throwable) {
                report(new LogicException('Unable to clean up a rejected video upload allocation.'));
            }

            throw $exception;
        }

        return new VideoUpload($upload->provider, $upload->assetId, $upload->uploadUrl, $upload->uploadId, $video->id);
    }
}
