<flux:editor.button
    icon="film"
    :tooltip="__('Insert attached video')"
    x-on:click="$dispatch('oceanix:insert-video', { model: $el.closest('[data-oceanix-editor-model]').dataset.oceanixEditorModel })"
/>
