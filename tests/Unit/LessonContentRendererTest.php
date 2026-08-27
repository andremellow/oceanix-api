<?php

use App\Models\Lesson;
use App\Services\Courses\LessonContentRenderer;

it('renders markdown and controlled responsive media blocks', function (): void {
    $lesson = new Lesson([
        'content_markdown' => "# Welcome\n\n:::image{src=\"https://example.com/photo.jpg\" align=\"right\" width=\"40%\" alt=\"Safety photo\"}",
    ]);

    $html = (string) app(LessonContentRenderer::class)->render($lesson);

    expect($html)->toContain('<h1>Welcome</h1>')
        ->toContain('lesson-media--right')
        ->toContain('--lesson-media-width:40%')
        ->toContain('alt="Safety photo"');
});

it('renders managed image library paths without binding them to an application host', function (): void {
    $html = app(LessonContentRenderer::class)->renderMarkdown(
        ':::image{src="/storage/content-images/safety.png" align="right" width="40%" alt="Safety procedure"}',
    )->toHtml();

    expect($html)->toContain('src="/storage/content-images/safety.png"')
        ->toContain('lesson-media--right')
        ->not->toContain('localhost');
});

it('strips html unsafe links and invalid image sources', function (): void {
    $lesson = new Lesson([
        'content_markdown' => '<script>alert(1)</script> [bad](javascript:alert(2))'."\n\n".':::image{src="javascript:alert(3)" align="right"}',
    ]);

    $html = (string) app(LessonContentRenderer::class)->render($lesson);

    expect($html)->not->toContain('<script')
        ->not->toContain('href="javascript:')
        ->not->toContain('<img');
});
