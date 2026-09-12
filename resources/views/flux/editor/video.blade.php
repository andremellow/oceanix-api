<flux:editor.button
    icon="film"
    :tooltip="__('Insert video')"
    data-editor-media-action="open-library"
    data-editor-media-family="video"
    data-editor-action-detail="open-video-library"
    x-on:click="$dispatch('oceanix-open-video-library', { model: $el.closest('[data-oceanix-editor-model]').dataset.oceanixEditorModel })"
/>
