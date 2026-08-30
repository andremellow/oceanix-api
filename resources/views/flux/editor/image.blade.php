<flux:editor.button
    icon="photo"
    :tooltip="__('Insert image')"
    x-on:click="$dispatch('oceanix-open-image-library', { model: $el.closest('[data-oceanix-editor-model]').dataset.oceanixEditorModel })"
/>
