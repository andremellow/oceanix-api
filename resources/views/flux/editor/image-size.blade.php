<flux:dropdown position="bottom" align="end">
    <flux:editor.button icon="arrows-pointing-out" :tooltip="__('Image size')" />
    <flux:menu>
        @foreach ([25, 40, 50, 75, 100] as $width)
            <flux:menu.item x-on:click="$el.closest('[data-flux-editor]').editor.chain().focus().updateAttributes('image', { width: '{{ $width }}' }).run()">{{ $width }}%</flux:menu.item>
        @endforeach
    </flux:menu>
</flux:dropdown>
