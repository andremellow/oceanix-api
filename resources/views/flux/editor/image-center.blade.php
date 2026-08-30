<flux:editor.button icon="bars-3" :tooltip="__('Center image')" x-on:click="$el.closest('[data-flux-editor]').editor.chain().focus().updateAttributes('image', { align: 'center' }).run()" />
