<?php

namespace App\Services\Courses;

use App\Models\Lesson;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class LessonContentRenderer
{
    public function render(Lesson $lesson): HtmlString
    {
        return $this->renderMarkdown((string) $lesson->content_markdown, $lesson->video?->formattedDuration());
    }

    public function renderMarkdown(string $markdown, ?string $videoDuration = null): HtmlString
    {
        $blocks = [];
        $index = 0;

        $markdown = preg_replace_callback('/^:::image\{([^}]*)\}\s*$/m', function (array $match) use (&$blocks, &$index): string {
            $attributes = $this->attributes($match[1]);
            $src = $attributes['src'] ?? '';
            $isSecureRemote = filter_var($src, FILTER_VALIDATE_URL) !== false && str_starts_with($src, 'https://');
            $isManagedLocal = str_starts_with($src, '/storage/content-images/');

            if (! $isSecureRemote && ! $isManagedLocal) {
                return '';
            }

            $align = in_array($attributes['align'] ?? '', ['left', 'right', 'center'], true) ? $attributes['align'] : 'center';
            $width = in_array($attributes['width'] ?? '', ['25%', '40%', '50%', '75%', '100%'], true) ? $attributes['width'] : '50%';
            $token = 'OCEANIXMEDIA'.($index++).'TOKEN';
            $classes = match ($align) {
                'left' => 'lesson-media lesson-media--left',
                'right' => 'lesson-media lesson-media--right',
                default => 'lesson-media lesson-media--center',
            };
            $blocks[$token] = sprintf(
                '<figure class="%s" style="--lesson-media-width:%s"><img src="%s" alt="%s" loading="lazy"></figure>',
                $classes, $width, e($src), e($attributes['alt'] ?? ''),
            );

            return "\n{$token}\n";
        }, $markdown) ?? $markdown;

        $markdown = preg_replace_callback('/^:::video\s*$/m', function () use ($videoDuration, &$blocks, &$index): string {
            $token = 'OCEANIXMEDIA'.($index++).'TOKEN';
            $blocks[$token] = $videoDuration === null
                ? '<div class="lesson-video-placeholder">'.e(__('No video attached')).'</div>'
                : '<div class="lesson-video-placeholder">'.e(__('Video')).' · '.e($videoDuration).'</div>';

            return "\n{$token}\n";
        }, $markdown) ?? $markdown;

        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        foreach ($blocks as $token => $block) {
            $html = str_replace(["<p>{$token}</p>", $token], $block, $html);
        }

        return new HtmlString($html);
    }

    /** @return array<string, string> */
    private function attributes(string $source): array
    {
        preg_match_all('/([a-z_]+)="([^"]*)"/', $source, $matches, PREG_SET_ORDER);

        return collect($matches)->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]])->all();
    }
}
