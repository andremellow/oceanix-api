@props(['course', 'version', 'platform' => false])
@php
    $previewLink = null;
    try {
        $previewActor = $platform ? app(\App\Services\Platform\PlatformAccess::class)->authorize() : auth()->user();
        $previewLink = app(\App\Actions\Courses\GenerateCoursePreviewLink::class)->retrieve($course->fresh(), $version->fresh(), $previewActor);
    } catch (\Illuminate\Auth\Access\AuthorizationException | \Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        // Revocation removes the credential as well as the action on every render.
    }
@endphp
@if($previewLink !== null)
    <section class="detail-card space-y-4 p-5" x-data="coursePreviewShare(@js(route($platform ? 'platform.shared-courses.preview-link' : 'courses.preview-link', ['course' => $course->id, 'version' => $version->id])), @js($previewLink), @js(['copied' => __('Link copied.'), 'manual' => __('Copy the selected link manually.'), 'failed' => __('Could not load the preview link. Please try again.')]))">
        <h2 class="text-base font-bold">{{ __('Public draft preview') }}</h2>
        <p class="text-sm text-[var(--ds-text-secondary)]">{{ __('Anyone with the link can review the saved draft for seven days. Unsaved changes are not included.') }}</p>
        <template x-if="state === 'absent' || state === 'expired'">
            <button type="button" @click="generate()" :disabled="busy" class="rounded-xl bg-[var(--ds-action-primary)] px-4 py-2 text-white disabled:opacity-60 focus-visible:outline-2"><span x-text="state === 'expired' ? @js(__('Generate new preview link')) : @js(__('Generate preview link'))"></span></button>
        </template>
        <div x-show="state === 'active'" class="space-y-3">
            <label class="block text-sm font-semibold">{{ __('Preview link') }}<input x-ref="link" :value="url" readonly @click="$el.select()" class="mt-2 block w-full min-w-0 rounded-xl border border-[var(--ds-border-default)] bg-white p-3 text-sm focus-visible:outline-2"></label>
            <p class="text-sm">{{ __('Expires') }}: <span x-text="formattedExpiry()"></span></p>
            <button type="button" @click="copy()" :disabled="busy" class="rounded-xl border border-[var(--ds-border-default)] bg-white px-4 py-2 focus-visible:outline-2">{{ __('Copy link') }}</button>
        </div>
        <p role="status" aria-live="polite" class="text-sm" x-text="message"></p>
    </section>
@endif
