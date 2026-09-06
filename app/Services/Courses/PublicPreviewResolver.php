<?php

namespace App\Services\Courses;

use App\Models\Course;
use App\Models\CoursePreviewLink;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Video;
use Illuminate\Support\Collection;

class PublicPreviewResolver
{
    public function __construct(private readonly CoursePreviewAuthority $authority, private readonly LessonContentRenderer $renderer) {}

    public function authoredVideo(Lesson $lesson): ?Video
    {
        return $this->renderer->splitAtVideo((string) $lesson->content_markdown) !== null ? $lesson->video : null;
    }

    public function resolve(#[\SensitiveParameter] string $token): CoursePreviewLink
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $link = CoursePreviewLink::query()->where('token_hash', hash('sha256', $token))->first() ?? abort(404);
        $version = CourseVersion::query()->find($link->course_version_id);
        $course = $version ? Course::withoutGlobalScopes()->find($version->course_id) : null;
        abort_unless($link->expires_at->isFuture() && $version && $course && $this->authority->eligible($course, $version), 410);
        $link->setRelation('courseVersion', $version);

        return $link;
    }

    public function items(CourseVersion $version): Collection
    {
        $course = Course::withoutGlobalScopes()->findOrFail($version->course_id);
        $eligible = fn (Lesson $lesson): bool => ($lesson->is_shared && $lesson->company_id === null)
            || (! $course->is_shared && ! $lesson->is_shared && (int) $lesson->company_id === (int) $course->company_id);
        $compositions = $version->moduleCompositions()->get();
        if ($compositions->isNotEmpty()) {
            $lessons = Lesson::query()->with(['video', 'questions.options'])->whereIn('id', $compositions->pluck('lesson_id'))->get()->filter($eligible)->keyBy('id');

            return $compositions->filter(fn ($row) => $lessons->has($row->lesson_id))->map(fn ($row) => ['kind' => 'composition', 'id' => $row->id, 'lesson' => $lessons[$row->lesson_id]])->values();
        }

        return $version->lessons()->with(['video', 'questions.options'])->get()->filter($eligible)->map(fn ($lesson) => ['kind' => 'lesson', 'id' => $lesson->id, 'lesson' => $lesson]);
    }

    public function item(CoursePreviewLink $link, string $kind, string $item): Lesson
    {
        $match = $this->items($link->courseVersion)->first(fn ($entry) => $entry['kind'] === $kind && (string) $entry['id'] === $item);

        return $match['lesson'] ?? abort(404);
    }
}
