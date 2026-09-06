<?php

namespace App\Services\Courses;

use App\Models\CoursePreviewLink;
use App\Models\Lesson;
use Illuminate\Support\HtmlString;

class CoursePreviewProjection
{
    public function __construct(private readonly PublicPreviewResolver $resolver, private readonly LessonContentRenderer $renderer) {}

    public function project(CoursePreviewLink $link, ?Lesson $lesson = null): array
    {
        $parts = $lesson ? $this->renderer->splitAtVideo((string) $lesson->content_markdown) : null;
        $hasVideo = $lesson && $this->resolver->authoredVideo($lesson) !== null;
        $before = $hasVideo ? $parts[0] : (string) ($lesson?->content_markdown ?? '');
        $after = $hasVideo ? $parts[1] : '';
        // A lesson has one current asset: repeated markers must not duplicate its player.
        while (($extra = $this->renderer->splitAtVideo($after)) !== null) {
            $after = implode('', $extra);
        }

        return [
            'title' => $link->courseVersion->title, 'description' => $link->courseVersion->description,
            'expires_at' => $link->expires_at,
            'items' => $this->resolver->items($link->courseVersion)->map(fn ($item) => ['kind' => $item['kind'], 'id' => $item['id'], 'title' => $item['lesson']->title])->all(),
            'selected' => $lesson ? ['title' => $lesson->title, 'body' => $this->body($before), 'after_video' => $this->body($after), 'has_video' => $hasVideo,
                'questions' => $lesson->questions->map(fn ($question) => ['prompt' => $question->prompt, 'choices' => $question->options->pluck('text')->all()])->all()] : null,
        ];
    }

    private function body(string $content): HtmlString
    {
        return new HtmlString(preg_replace('/<(\/?)(h1)\b/i', '<$1h3', (string) $this->renderer->renderContent($content)));
    }
}
