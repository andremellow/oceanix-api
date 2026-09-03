<?php

namespace App\Actions\Videos;

use App\Contracts\VideoProvider;
use App\Data\Video\VideoUpload;
use App\Enums\ModuleVersionStatus;
use App\Enums\VideoStatus;
use App\Models\Account;
use App\Models\Lesson;
use App\Models\Video;
use App\Services\Audit\AuditLogger;
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
    public function __construct(
        private readonly VideoProvider $videoProvider,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Lesson $lesson, int $maxDurationSeconds = 7200, ?Account $platformActor = null): VideoUpload
    {
        DB::transaction(fn (): Lesson => $this->assertEligible($lesson->id, $platformActor), 3);

        $upload = $this->videoProvider->createUpload(
            $lesson->title !== '' ? $lesson->title : __('Untitled lesson'),
            $maxDurationSeconds,
            $lesson->company_id === null ? 'platform' : 'company:'.$lesson->company_id,
        );

        try {
            $video = DB::transaction(function () use ($lesson, $upload, $platformActor): Video {
                $lesson = $this->assertEligible($lesson->id, $platformActor);

                if ($platformActor === null) {
                    $lesson->video?->delete();
                }

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
                ], platformActor: $platformActor);

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

    private function assertEligible(int $lessonId, ?Account $platformActor): Lesson
    {
        $lesson = Lesson::query()->lockForUpdate()->findOrFail($lessonId);
        if ($platformActor === null) {
            return $lesson;
        }

        $authorized = Account::query()->whereKey($platformActor->id)->where('is_platform_admin', true)->where('status', 'active')->exists();
        if (! $authorized || $lesson->company_id !== null || ! $lesson->is_shared || $lesson->status !== ModuleVersionStatus::Draft->value || $lesson->lineage_archived_at !== null) {
            throw new LogicException('Videos can only be changed on platform-owned shared module drafts.');
        }

        return $lesson;
    }
}
