<flux:editor.button
    icon="document-arrow-up"
    :tooltip="__('Insert PDF')"
    :aria-label="__('Insert PDF')"
    data-editor-media-action="open-library"
    data-editor-media-family="pdf"
    data-editor-action-detail="open-pdf-modal"
    x-on:mousedown.prevent
    x-on:click="window.oceanixOpenPdf($el)"
/>
