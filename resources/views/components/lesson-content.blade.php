@props(['lesson' => null, 'markdown' => null, 'video' => null])

@php
    $renderer = app(App\Services\Courses\LessonContentRenderer::class);
    $html = $lesson
        ? $renderer->render($lesson)
        : $renderer->renderMarkdown((string) $markdown, $video['duration'] ?? null);
@endphp

<div {{ $attributes->class(['lesson-content flow-root text-[15px] leading-7 text-[#3d464c]']) }}>
    {!! $html !!}
</div>
