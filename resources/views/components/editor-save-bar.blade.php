@props(['dirty' => false, 'dirtyModuleCount' => 0, 'error' => null, 'uploadInProgress' => false, 'course' => false])

<div class="fixed inset-x-0 bottom-0 z-40 m-0 border-t border-[#dce3e7] bg-white/95 px-5 py-4 shadow-[0_-12px_30px_rgba(31,38,43,.08)] backdrop-blur" style="bottom: 0; margin-bottom: 0;" role="region" aria-label="{{ __('Draft save actions') }}" aria-busy="false" wire:loading.attr="aria-busy" wire:target="saveDraft" x-init="observeSaveBar($el); saveBarObserver.disconnect(); saveBarObserver = new ResizeObserver(() => $root.style.setProperty('--editor-save-bar-height', `${$el.getBoundingClientRect().height}px`)); saveBarObserver.observe($el)" data-dirty="{{ $dirty ? 'true' : 'false' }}" data-dirty-module-count="{{ $dirtyModuleCount }}">
    <div class="mx-auto grid max-w-[1480px] gap-3">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div role="status" aria-live="polite" aria-atomic="true" class="text-sm font-semibold">
                <span wire:loading.remove wire:target="saveDraft">
                    @if ($course)
                        <span x-show="pageDirty && dirtyModuleCount() === 0" class="text-amber-700">{{ __('Unsaved changes') }}</span>
                        <span x-show="pageDirty && dirtyModuleCount() === 1" class="text-amber-700">{{ __('1 module has unsaved changes') }}</span>
                        <span x-show="pageDirty && dirtyModuleCount() > 1" x-text="`${dirtyModuleCount()} {{ __('modules have unsaved changes') }}`" class="text-amber-700"></span>
                    @else
                        <span x-show="pageDirty" class="text-amber-700">{{ __('Unsaved changes') }}</span>
                    @endif
                    <span x-show="! pageDirty" class="text-emerald-700">{{ __('All changes saved') }}</span>
                </span>
                <span wire:loading wire:target="saveDraft" class="text-[#1c6b84]">{{ __('Saving draft…') }}</span>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <flux:button wire:click="saveDraft(false)" x-on:click="saving = true" x-bind:disabled="! pageDirty || saving" wire:loading.attr="disabled" wire:target="saveDraft" variant="primary" class="w-full sm:w-auto">{{ __('Save and continue') }}</flux:button>
                <flux:button wire:click="saveDraft(true)" x-on:click="saving = true" x-bind:disabled="saving || {{ Js::from($uploadInProgress) }}" wire:loading.attr="disabled" wire:target="saveDraft" variant="ghost" class="w-full sm:w-auto">{{ __('Save and close') }}</flux:button>
            </div>
        </div>
        @if ($error)<div role="alert" class="w-full"><flux:callout variant="danger" :heading="$error" /></div>@endif
    </div>
</div>
