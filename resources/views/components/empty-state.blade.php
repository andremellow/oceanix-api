@props(['icon' => 'inbox', 'title', 'description' => null])

{{-- Dashed neutral surface, a specific title, and one sentence about when data appears. --}}
<div {{ $attributes->merge(['class' => 'rounded-[20px] border border-dashed border-[#d7dee3] bg-white/60 p-8 text-center']) }}>
    <span class="mx-auto grid size-11 place-items-center rounded-2xl bg-[#eef3f6] text-[#7d878e]">
        <flux:icon :name="$icon" class="size-5" />
    </span>
    <p class="mt-4 text-base font-bold text-[#262d33]">{{ $title }}</p>
    @if ($description)
        <p class="mx-auto mt-1 max-w-md text-sm leading-6 text-[#6f797f]">{{ $description }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-5 flex justify-center">{{ $slot }}</div>
    @endif
</div>
