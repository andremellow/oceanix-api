<flux:editor.button
    icon="film"
    :tooltip="__('Insert video')"
    x-on:click="$dispatch('oceanix-open-video-library', { model: $el.closest('[data-oceanix-editor-model]').dataset.oceanixEditorModel })"
/>
