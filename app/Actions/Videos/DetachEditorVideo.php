<?php

namespace App\Actions\Videos;

use App\Actions\Videos\Concerns\LocksEditorMediaTarget;
use App\Models\Account;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Video;
use App\Services\CourseEditor\EditorRevision;
use Illuminate\Support\Facades\DB;

final class DetachEditorVideo
{
    use LocksEditorMediaTarget;

    public function __construct(private readonly EditorRevision $revisions) {}

    public function forCompanyEditor(Lesson $lesson, int $videoId, User $actor, string $expectedRevision): void
    {
        $this->detach($lesson, $videoId, $actor, null, $expectedRevision);
    }

    public function forPlatformEditor(Lesson $lesson, int $videoId, Account $actor, string $expectedRevision): void
    {
        $this->detach($lesson, $videoId, null, $actor, $expectedRevision);
    }

    private function detach(Lesson $lesson, int $videoId, ?User $actor, ?Account $platformActor, string $expectedRevision): void
    {
        $this->assertExpectedEditorMediaRevision($expectedRevision);
        DB::transaction(function () use ($lesson, $videoId, $actor, $platformActor, $expectedRevision): void {
            $target = $this->lockEditorMediaTarget($lesson, $actor, $platformActor, $expectedRevision, $this->revisions);
            $locked = $target['lesson'];
            $video = Video::query()->where('lesson_id', $locked->id)->whereKey($videoId)->where('is_current', true)->lockForUpdate()->firstOrFail();
            $video->update(['is_current' => false]);
        }, 3);
    }
}
