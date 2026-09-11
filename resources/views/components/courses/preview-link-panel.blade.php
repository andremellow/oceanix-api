@props(['panel' => null, 'guardEditor' => false, 'editorState' => 'clean', 'editorDirty' => false])
@if($panel !== null)
    <section
        class="detail-card space-y-4 p-5"
        data-course-preview-panel
        data-preview-source="saved-draft"
        x-data="{
            ...coursePreviewShare(@js($panel['endpoint']), @js($panel['link']), @js(['copied' => __('Link copied.'), 'manual' => __('Copy the selected link manually.'), 'failed' => __('Could not load the preview link. Please try again.')])),
            guardEditor: @js($guardEditor),
            editorState: @js($editorState),
            editorDirty: @js($editorDirty),
            editorUnsafe() {
                if (! this.guardEditor) return false;
                return this.editorDirty || ['saving', 'validation-error', 'conflict', 'network-error', 'permission-lost'].includes(this.editorState);
            },
        }"
        x-on:oceanix:editor-state-changed.window="editorState = $event.detail.state; editorDirty = $event.detail.dirty">
        <h2 class="text-base font-bold">{{ __('Public draft preview') }}</h2>
        <p class="text-sm text-[var(--ds-text-secondary)]">{{ __('Anyone with the link can review the saved draft for seven days. Unsaved changes are not included.') }}</p>
        <p x-cloak x-show="editorUnsafe()" data-editor-preview-guard role="status" class="rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-900">{{ __('Save or resolve the current editor error before opening the saved-draft preview. Your staged changes will remain in this editor.') }}</p>
        <template x-if="state === 'absent' || state === 'expired'">
            <button type="button" @click="if (! editorUnsafe()) generate()" :disabled="busy || editorUnsafe()" data-editor-preview-action="generate" class="rounded-xl bg-[var(--ds-action-primary)] px-4 py-2 text-white disabled:opacity-60"><span x-text="state === 'expired' ? @js(__('Generate new preview link')) : @js(__('Generate preview link'))"></span></button>
        </template>
        <div x-show="state === 'active' && ! editorUnsafe()" class="space-y-3">
            <label class="block text-sm font-semibold">{{ __('Preview link') }}<input x-ref="link" :value="url" readonly @click="$el.select()" data-editor-preview-action="select-link" class="mt-2 block w-full min-w-0 rounded-xl border border-[var(--ds-border-default)] bg-white p-3 text-sm"></label>
            <p class="text-sm">{{ __('Expires') }}: <span x-text="formattedExpiry()"></span></p>
            <button type="button" @click="if (! editorUnsafe()) copy()" :disabled="busy || editorUnsafe()" data-editor-preview-action="copy" class="rounded-xl border border-[var(--ds-border-default)] bg-white px-4 py-2 disabled:opacity-60">{{ __('Copy link') }}</button>
        </div>
        <p role="status" aria-live="polite" class="text-sm" x-text="message"></p>
    </section>
@endif
