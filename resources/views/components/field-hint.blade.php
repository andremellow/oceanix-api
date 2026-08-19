@props(['text'])

{{--
    Explains a field without stealing layout from it. Inline help text wraps badly in narrow
    columns and drags the whole row out of alignment, so the hint lives behind an icon.
    The trigger is a real button: it is keyboard reachable, and the text is also exposed as
    its accessible name, so a screen reader gets the explanation without the tooltip.
--}}
<flux:tooltip :content="$text">
    <button type="button"
        {{ $attributes->merge(['class' => 'grid size-4 shrink-0 place-items-center rounded-full text-[#9aa3a9] transition hover:text-[#1c6b84] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#3e8ba3]']) }}
        x-on:click.stop.prevent
        aria-label="{{ $text }}">
        <flux:icon.information-circle class="size-4" />
    </button>
</flux:tooltip>
