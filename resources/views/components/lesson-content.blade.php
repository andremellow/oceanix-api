@props(['lesson' => null, 'markdown' => null, 'video' => null, 'documentUrl' => null])

@php
    $renderer = app(App\Services\Courses\LessonContentRenderer::class);
    $html = $lesson
        ? $renderer->render($lesson)
        : $renderer->renderContent((string) $markdown, $video['duration'] ?? null);
    if ($documentUrl) {
        $html = app(App\Services\Documents\LessonDocumentLinks::class)->map((string) $html, $documentUrl);
    }
@endphp

<div {{ $attributes->class(['lesson-content flow-root text-[15px] leading-7 text-[#3d464c]']) }}>
    {!! $html !!}
</div>
