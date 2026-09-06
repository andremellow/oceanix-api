@component('layouts::platform', ['title' => __('Preview as learner')])
    <div class="space-y-7">
        @if($lesson)
            <x-page-hero :kicker="$version->title" :title="$lesson->title" :description="$lesson->description">
                <span class="status-pill status-pill--accent">{{ __('Preview as learner') }}</span>
                <span class="status-pill status-pill--accent">{{ __('ui.watched_percentage', ['percentage' => 0]) }}</span>
                <flux:button :href="route('platform.shared-courses.preview', [$course, $version])" variant="ghost" size="sm">{{ __('ui.back_to_assignment') }}</flux:button>
            </x-page-hero>
            @php
                $renderer = app(App\Services\Courses\LessonContentRenderer::class);
                $parts = $renderer->splitAtVideo((string) $lesson->content_markdown);
                $body = $parts === null ? $renderer->editorContent((string) $lesson->content_markdown) : $parts[0];
            @endphp
            @if(trim($body) !== '')
                <article class="detail-card"><div class="lesson-content flow-root break-words text-[15px] leading-7 text-[#3d464c]">{!! $body !!}</div></article>
            @endif
            @if($parts !== null)
                <section class="overflow-hidden rounded-[24px] border border-[#dce3e7] bg-white shadow-sm" data-course-preview-player data-endpoint="{{ route('platform.shared-courses.preview-playback', compact('course', 'version', 'kind', 'item')) }}" data-ended="{{ __('This preview is no longer available.') }}" data-failed="{{ __('Video unavailable. Please try again.') }}" data-loading="{{ __('Loading video…') }}" data-ready="{{ __('Video ready.') }}">
                    <div class="bg-[#0f1a20]"><video class="aspect-video w-full" controls playsinline preload="none" aria-label="{{ __('Video preview') }}"></video></div>
                    <div class="space-y-2 p-4 sm:p-5">
                        <button type="button" class="rounded-xl bg-[var(--ds-action-primary)] px-4 py-2 text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ds-focus-ring)]" data-play>{{ __('Play or retry video') }}</button>
                        <p class="text-sm" data-status role="status" aria-live="polite"></p>
                        <p class="text-sm" data-ended-guidance hidden>{{ __('Return to the course and select an available version.') }}</p>
                    </div>
                </section>
                @if(trim($parts[1]) !== '')
                    <article class="detail-card"><div class="lesson-content flow-root break-words text-[15px] leading-7 text-[#3d464c]">{!! $parts[1] !!}</div></article>
                @endif
            @endif
            <section class="detail-card">
                <span class="detail-card-icon"><flux:icon.clipboard-document-check class="size-5" /></span>
                <h2 class="detail-card-title">{{ __('Assessment') }}</h2>
                <p class="mt-1 text-sm text-[#6f797f]">{{ __('Questions and choices are shown for review. Answers cannot be submitted.') }}</p>
                <div class="mt-5 space-y-4">
                    @foreach($lesson->questions as $question)
                        <fieldset class="rounded-[18px] border border-[#e4e9ec] p-4 sm:p-5">
                            <legend class="font-semibold text-[#262d33]">{{ $question->position }}. {{ $question->prompt }}</legend>
                            <p class="mt-1 text-xs text-[#8a9298]">{{ $question->type->label() }} · {{ trans_choice('ui.attempts_allowed', $question->max_attempts, ['count' => $question->max_attempts]) }}</p>
                            <div class="mt-4 space-y-2">
                                @foreach($question->options as $option)
                                    <label class="role-option"><input type="{{ $question->type === App\Enums\QuestionType::MultipleChoice ? 'checkbox' : 'radio' }}" name="preview-question-{{ $question->id }}" class="mt-0.5 size-4 border-[#8e989f] text-[#1c6b84] focus:ring-[#3e8ba3]"><span class="text-sm text-[#262d33]">{{ $option->text }}</span></label>
                                @endforeach
                            </div>
                            <flux:button type="button" variant="primary" size="sm" class="mt-4" disabled>{{ __('ui.submit_answer') }}</flux:button>
                        </fieldset>
                    @endforeach
                </div>
            </section>
        @else
            <x-training.course-overview
                :$course :$version :lessons="$items->pluck('lesson')" :progress="collect()"
                :preview="true" :back-url="route('platform.shared-courses.show', $course)"
                :lesson-urls="$items->mapWithKeys(fn ($entry) => [$entry['lesson']->id => route('platform.shared-courses.preview', ['course' => $course, 'version' => $version, 'kind' => $entry['kind'], 'item' => $entry['id']])])->all()" />
        @endif
    </div>
@endcomponent
