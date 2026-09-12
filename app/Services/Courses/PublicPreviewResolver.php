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
    public function __construct(
        private readonly CoursePreviewAuthority $authority,
        private readonly LessonContentRenderer $renderer,
        private readonly CourseVersionComposition $composition,
    ) {}

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
        $state = $this->composition->inspect($version);
        abort_if($state['mode'] === CourseVersionComposition::Mixed, 409, __('ui.mixed_composition_error'));

        if ($state['mode'] === CourseVersionComposition::Modules) {
            return $state['reusableRows']->filter(fn ($row) => $row->moduleVersion && $eligible($row->moduleVersion))
                ->map(fn ($row) => ['kind' => 'composition', 'id' => $row->id, 'lesson' => $row->moduleVersion])->values();
        }

        $mirrors = $state['mirroredDirectRows']->keyBy('lesson_id');

        return $state['directLessons']->filter($eligible)->map(function (Lesson $lesson) use ($mirrors): array {
            $mirror = $mirrors->get($lesson->id);

            return [
                'kind' => $mirror === null ? 'lesson' : 'composition',
                'id' => $mirror?->id ?? $lesson->id,
                'lesson' => $lesson,
            ];
        })->values();
    }

    public function item(CoursePreviewLink $link, string $kind, string $item): Lesson
    {
        $match = $this->items($link->courseVersion)->first(fn ($entry) => $entry['kind'] === $kind && (string) $entry['id'] === $item);

        return $match['lesson'] ?? abort(404);
    }
}
