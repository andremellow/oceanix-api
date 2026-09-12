<flux:editor.button
    icon="photo"
    :tooltip="__('Insert image')"
    data-editor-media-action="open-library"
    data-editor-media-family="image"
    data-editor-action-detail="open-image-library"
    x-on:click="$dispatch('oceanix-open-image-library', { model: $el.closest('[data-oceanix-editor-model]').dataset.oceanixEditorModel })"
/>
