@props([
    'model',
    'title',
    'consequence',
    'submitLabel',
    'submitAction',
    'actionHook' => 'remove',
    'actionDetail' => 'remove',
    'targetKey' => 'editor',
    'hookType' => 'structure',
])

<flux:modal
    wire:model.self="{{ $model }}"
    :closable="false"
    class="w-[calc(100vw-2rem)] max-w-lg"
    data-editor-destructive-modal
    data-target-key="{{ $targetKey }}"
    x-data="{ destructivePending: false }"
    x-bind:data-destructive-state="destructivePending ? 'pending' : 'idle'"
    x-bind:disable-click-outside="destructivePending"
    x-bind:disable-escape="destructivePending"
    x-on:keydown.escape.window.capture="if (destructivePending) { $event.preventDefault(); $event.stopImmediatePropagation(); }"
    x-on:editor-operation-finished.window="destructivePending = false"
    x-on:editor-request-terminal.window="destructivePending = false"
    aria-labelledby="{{ $model }}-title"
    aria-describedby="{{ $model }}-consequence">
    <form
        wire:submit="{{ $submitAction }}"
        x-on:submit="destructivePending = true; $refs.pending.hidden = false"
        x-bind:aria-busy="destructivePending ? 'true' : null"
        class="min-w-0 space-y-5"
        data-editor-destructive-confirmation
        data-destructive-action="{{ $actionHook }}"
        data-record-key="{{ $targetKey }}">
        <div class="min-w-0">
            <flux:heading id="{{ $model }}-title" size="lg" class="break-words [overflow-wrap:anywhere]">{{ $title }}</flux:heading>
            <flux:text id="{{ $model }}-consequence" class="mt-2 break-words [overflow-wrap:anywhere]">{{ $consequence }}</flux:text>
        </div>

        {{ $slot }}

        <p
            x-ref="pending"
            hidden
            x-bind:hidden="! destructivePending"
            data-editor-destructive-pending
            data-target-key="{{ $targetKey }}"
            role="status"
            aria-live="polite"
            class="rounded-xl border border-[#cbd8de] bg-[#f4f8fa] px-3 py-2 text-sm font-semibold text-[#465159]">
            {{ __(':action in progress for :target…', ['action' => $submitLabel, 'target' => $title]) }}
        </p>

        <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:justify-end">
            <flux:button
                type="button"
                wire:click="cancelDestructiveConfirmation('{{ $model }}')"
                data-editor-destructive-cancel
                x-bind:disabled="destructivePending"
                variant="ghost"
                class="w-full whitespace-normal sm:w-auto">
                {{ __('Cancel') }}
            </flux:button>
            @if ($hookType === 'media')
                <flux:button
                    type="submit"
                    data-editor-media-action="{{ $actionHook }}"
                    data-editor-action-detail="{{ $actionDetail }}"
                    data-editor-target-key="{{ $targetKey }}"
                    data-editor-destructive-submit
                    x-bind:disabled="destructivePending"
                    variant="danger"
                    class="w-full whitespace-normal sm:w-auto">
                    {{ $submitLabel }}
                </flux:button>
            @else
                <flux:button
                    type="submit"
                    data-editor-structure-action="{{ $actionHook }}"
                    data-editor-action-detail="{{ $actionDetail }}"
                    data-editor-target-key="{{ $targetKey }}"
                    data-editor-destructive-submit
                    x-bind:disabled="destructivePending"
                    variant="danger"
                    class="w-full whitespace-normal sm:w-auto">
                    {{ $submitLabel }}
                </flux:button>
            @endif
        </div>
    </form>
</flux:modal>
