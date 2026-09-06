@extends('course-preview.layout')
@section('content')
<section class="detail-card p-6">
    <h1 class="text-2xl font-bold tracking-tight">{{ $preview['title'] }}</h1>
    @if($preview['description'])<p class="mt-3 whitespace-pre-line text-[var(--ds-text-secondary)]">{{ $preview['description'] }}</p>@endif
    <p class="mt-4 text-sm text-[var(--ds-text-secondary)]">{{ __('Editorial review only. This preview does not record training progress.') }}</p>
</section>
<div class="grid items-start gap-6 lg:grid-cols-[250px_minmax(0,1fr)]">
    <nav class="detail-card min-w-0 p-5" aria-label="{{ __('Course contents') }}">
        <details open>
            <summary class="cursor-pointer font-bold">{{ __('Course contents') }}</summary>
            <ol class="mt-4 space-y-3">
                <li><a class="underline focus-visible:outline-2" href="{{ route('course-preview.show', ['token' => $token]) }}">{{ __('Overview') }}</a></li>
                @foreach($preview['items'] as $entry)
                    <li><a class="block break-words rounded-lg p-2 hover:bg-[var(--ds-accent-subtle)] focus-visible:outline-2" @if($kind === $entry['kind'] && (string)$item === (string)$entry['id']) aria-current="page" @endif href="{{ route('course-preview.item', ['token' => $token, 'kind' => $entry['kind'], 'item' => $entry['id']]) }}">{{ $entry['title'] }}</a></li>
                @endforeach
            </ol>
        </details>
    </nav>
    <article class="detail-card min-w-0 space-y-6 overflow-hidden p-5 sm:p-7">
        @if($preview['selected'])
            <h2 class="text-xl font-bold">{{ $preview['selected']['title'] }}</h2>
            <div class="lesson-content break-words">{{ $preview['selected']['body'] }}</div>
            @if($preview['selected']['has_video'])
                <section data-course-preview-player data-endpoint="{{ route('course-preview.playback', compact('token', 'kind', 'item')) }}" data-ended="{{ __('This preview has ended.') }}" data-failed="{{ __('Video unavailable. Please try again.') }}" data-loading="{{ __('Loading video…') }}" data-ready="{{ __('Video ready.') }}">
                    <video class="w-full rounded-xl" controls playsinline preload="none" aria-label="{{ __('Video preview') }}"></video>
                    <button type="button" class="mt-3 rounded-xl bg-[var(--ds-action-primary)] px-4 py-2 text-white focus-visible:outline-2" data-play>{{ __('Play or retry video') }}</button>
                    <p class="mt-2 text-sm" data-status role="status" aria-live="polite"></p>
                </section>
            @endif
            @if(count($preview['selected']['questions']))
                <section class="space-y-5" aria-label="{{ __('Assessment preview') }}">
                    <h2 class="text-xl font-bold">{{ __('Assessment preview') }}</h2>
                    <p class="text-sm text-[var(--ds-text-secondary)]">{{ __('Questions and choices are shown for review. Answers cannot be submitted.') }}</p>
                    @foreach($preview['selected']['questions'] as $question)
                        <section class="rounded-xl border border-[var(--ds-border-default)] p-4">
                            <h3 class="font-semibold">{{ $question['prompt'] }}</h3>
                            <ul class="mt-3 list-disc space-y-2 pl-6">@foreach($question['choices'] as $choice)<li>{{ $choice }}</li>@endforeach</ul>
                        </section>
                    @endforeach
                </section>
            @endif
        @else
            <h2 class="text-xl font-bold">{{ __('Overview') }}</h2>
            <p>{{ count($preview['items']) ? __('Choose an item from the course contents to begin reviewing.') : __('This draft has no content yet.') }}</p>
        @endif
    </article>
</div>
@endsection
