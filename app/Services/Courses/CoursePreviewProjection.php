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
        return [
            'title' => $link->courseVersion->title, 'description' => $link->courseVersion->description,
            'expires_at' => $link->expires_at,
            'items' => $this->resolver->items($link->courseVersion)->map(fn ($item) => ['kind' => $item['kind'], 'id' => $item['id'], 'title' => $item['lesson']->title])->all(),
            'selected' => $lesson ? ['title' => $lesson->title, 'body' => new HtmlString(preg_replace('/<(\/?)(h1)\b/i', '<$1h3', (string) $this->renderer->render($lesson))), 'has_video' => $lesson->video !== null,
                'questions' => $lesson->questions->map(fn ($question) => ['prompt' => $question->prompt, 'choices' => $question->options->pluck('text')->all()])->all()] : null,
        ];
    }
}
