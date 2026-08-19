@props(['hint' => null])

{{--
    A field label that can carry a hint icon.

    The icon lives inside the label rather than in a wrapper around it. Flux styles and
    spaces a label only when `ui-label` is a direct child of the field, and the element is
    `inline-flex`, so it carries a small natural offset — wrapping it drops both and lifts
    the control a few pixels above the neighbouring fields in the row.
--}}
<flux:label {{ $attributes }}>
    {{ $slot }}

    @if ($hint)
        <x-field-hint :text="$hint" class="ms-1.5" />
    @endif
</flux:label>
