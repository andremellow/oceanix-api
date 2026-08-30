@props(['assignment', 'lesson', 'playbackUrl', 'posterUrl', 'playbackError', 'unlocked', 'percentage'])

@if ($playbackError !== null)
    <x-empty-state
        icon="exclamation-triangle"
        :title="__('ui.video_playback_failed')"
        :description="$playbackError" />
@elseif ($lesson->video === null || ! $lesson->video->isPlayable())
    <x-empty-state
        icon="film"
        :title="__('ui.video_unavailable')"
        :description="__('ui.video_unavailable_help')" />
@else
    <section
        class="overflow-hidden rounded-[22px] border border-[#dde3e7] bg-white shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]"
        x-data="lessonPlayer({
            playbackUrl: @js($playbackUrl),
            poster: @js($posterUrl),
            eventsUrl: @js(route('my-training.events', ['assignment' => $assignment, 'lesson' => $lesson])),
            playbackAuthUrl: @js(route('my-training.playback', ['assignment' => $assignment, 'lesson' => $lesson])),
            sessionId: @js(session()->getId()),
            unlocked: @js($unlocked),
        })">
        <div class="bg-[#0f1a20]">
            <video x-ref="video" class="aspect-video w-full" controls playsinline></video>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-4 p-4 sm:p-5">
            <div class="min-w-0 flex-1">
                <div class="mb-1.5 flex items-center justify-between text-[11px] font-bold text-[#6f797f]">
                    <span>{{ __('ui.watched') }}</span>
                    <span x-text="`${Math.max(percentage, @js($percentage))}%`"></span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-[#e9edf0]">
                    <div class="h-full rounded-full bg-[#1c6b84] transition-all" :style="`width: ${Math.max(percentage, @js($percentage))}%`"></div>
                </div>
            </div>
            <p class="text-xs text-[#8a9298]">{{ __('ui.watch_threshold', ['percentage' => $lesson->minimum_watch_percentage]) }}</p>
        </div>
        <p class="px-4 pb-4 text-xs font-semibold text-[#9a6a1a] sm:px-5" x-show="blockedSeek" x-cloak>{{ __('ui.seek_blocked') }}</p>
        <p class="px-4 pb-4 text-xs font-semibold text-[#b23a3a] sm:px-5" x-show="error" x-text="error" x-cloak></p>
    </section>
@endif
