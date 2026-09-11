<?php

namespace App\Actions\Videos;

use App\Actions\Videos\Concerns\LocksEditorMediaTarget;
use App\Enums\VideoStatus;
use App\Models\Account;
use App\Models\User;
use App\Models\Video;
use App\Services\Audit\AuditLogger;
use App\Services\CourseEditor\EditorRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FailVideoUpload
{
    use LocksEditorMediaTarget;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EditorRevision $revisions,
    ) {}

    public function forCompanyEditor(Video $video, User $actor, string $expectedRevision): Video
    {
        return $this->fail($video, $expectedRevision, $actor, null);
    }

    public function forPlatformEditor(Video $video, Account $actor, string $expectedRevision): Video
    {
        return $this->fail($video, $expectedRevision, null, $actor);
    }

    private function fail(Video $video, string $expectedRevision, ?User $actor, ?Account $platformActor): Video
    {
        $this->assertExpectedEditorMediaRevision($expectedRevision);

        return DB::transaction(function () use ($video, $expectedRevision, $actor, $platformActor): Video {
            $target = $this->lockEditorMediaTarget($video->lesson()->firstOrFail(), $actor, $platformActor, $expectedRevision, $this->revisions);
            $video = Video::query()->where('lesson_id', $target['lesson']->id)->lockForUpdate()->findOrFail($video->id);
            if (! in_array($video->status, [VideoStatus::Uploading, VideoStatus::Processing], true)) {
                throw ValidationException::withMessages(['video' => __('Only an active upload can be marked as failed.')]);
            }

            $video->update(['status' => VideoStatus::Failed]);
            $this->audit->log('lesson.video_upload_failed', $target['lesson'], after: [
                'provider' => $video->provider,
                'asset_id' => $video->provider_asset_id,
            ], platformActor: $target['platform_actor']);

            return $video->refresh();
        }, 3);
    }
}
