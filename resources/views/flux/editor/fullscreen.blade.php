<flux:editor.button
    icon="arrows-pointing-out"
    :tooltip="__('Toggle full screen')"
    x-on:click="window.oceanixToggleEditorFullscreen($el.closest('[data-oceanix-editor-model]'))"
/>
