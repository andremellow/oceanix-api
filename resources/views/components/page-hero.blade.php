@props(['kicker' => null, 'title', 'description' => null, 'descriptionClass' => 'max-w-2xl'])

<section class="admin-hero">
    @if ($kicker || ! $slot->isEmpty())
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            @if ($kicker)
                <span class="admin-kicker">{{ $kicker }}</span>
            @endif
            @if (! $slot->isEmpty())
                <div class="flex flex-wrap items-center gap-2 sm:justify-end">{{ $slot }}</div>
            @endif
        </div>
    @endif

    <h1 class="{{ $kicker || ! $slot->isEmpty() ? 'mt-3' : '' }} text-2xl font-bold tracking-tight text-[#1f262b]">{{ $title }}</h1>
    @if ($description)
        <p @class(['mt-2 text-sm leading-6 text-[#5f6a71]', $descriptionClass])>{{ $description }}</p>
    @endif
</section>
