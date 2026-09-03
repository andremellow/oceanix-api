<?php

namespace App\Services\Courses;

use App\Models\Lesson;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class LessonContentRenderer
{
    private const HTML_VIDEO_MARKER = '/<div\s+data-oceanix-video(?:="")?\s*>.*?<\/div>/s';

    public function __construct(private readonly LessonContentSanitizer $sanitizer) {}

    public function render(Lesson $lesson): HtmlString
    {
        return $this->renderContent((string) $lesson->content_markdown, $lesson->video?->formattedDuration());
    }

    public function editorContent(string $content): string
    {
        if ($this->isHtml($content)) {
            return $this->sanitizer->sanitize($content);
        }

        $blocks = [];
        $index = 0;
        $content = preg_replace_callback('/^:::image\{([^}]*)\}\s*$/m', function (array $match) use (&$blocks, &$index): string {
            $attributes = $this->attributes($match[1]);
            $token = 'OCEANIXEDITORMEDIA'.($index++).'TOKEN';
            $align = in_array($attributes['align'] ?? '', ['left', 'right', 'center'], true) ? $attributes['align'] : 'center';
            $width = rtrim($attributes['width'] ?? '50%', '%');
            $blocks[$token] = '<img src="'.e($attributes['src'] ?? '').'" alt="'.e($attributes['alt'] ?? '').'" data-align="'.$align.'" data-width="'.$width.'">';

            return "\n{$token}\n";
        }, $content) ?? $content;
        $content = preg_replace_callback('/^:::video\s*$/m', function () use (&$blocks, &$index): string {
            $token = 'OCEANIXEDITORMEDIA'.($index++).'TOKEN';
            $blocks[$token] = '<div data-oceanix-video></div>';

            return "\n{$token}\n";
        }, $content) ?? $content;
        $html = (string) Str::markdown($content, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        foreach ($blocks as $token => $block) {
            $html = str_replace(["<p>{$token}</p>", $token], $block, $html);
        }

        return $this->sanitizer->sanitize($html);
    }

    public function containsVideo(string $content): bool
    {
        return preg_match(self::HTML_VIDEO_MARKER, $content) === 1
            || preg_match('/^:::video\s*$/m', $content) === 1;
    }

    /** @return array{0: string, 1: string}|null */
    public function splitAtVideo(string $content): ?array
    {
        $parts = preg_split(self::HTML_VIDEO_MARKER, $this->editorContent($content), 2);

        return is_array($parts) && count($parts) === 2 ? [$parts[0], $parts[1]] : null;
    }

    public function renderContent(string $content, ?string $videoDuration = null): HtmlString
    {
        if (! $this->isHtml($content)) {
            return $this->renderMarkdown($content, $videoDuration);
        }

        $html = $this->sanitizer->sanitize($content);
        $placeholder = $videoDuration === null
            ? '<div class="lesson-video-placeholder">'.e(__('No video attached')).'</div>'
            : '<div class="lesson-video-placeholder">'.e(__('Video')).' · '.e($videoDuration).'</div>';
        $html = preg_replace(self::HTML_VIDEO_MARKER, $placeholder, $html) ?? $html;

        return new HtmlString($html);
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

    private function isHtml(string $content): bool
    {
        return preg_match('/<\/?(?:p|h[1-3]|strong|em|ul|ol|li|blockquote|img|div|br|hr|pre|code|a)\b/i', $content) === 1;
    }
}
