@props(['kicker' => null, 'title', 'description' => null])

<section class="admin-hero">
    <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            @if ($kicker)
                <span class="admin-kicker">{{ $kicker }}</span>
            @endif
            <h1 class="mt-3 text-2xl font-bold tracking-tight text-[#1f262b]">{{ $title }}</h1>
            @if ($description)
                <p class="mt-2 max-w-2xl text-sm leading-6 text-[#5f6a71]">{{ $description }}</p>
            @endif
        </div>
        @if (! $slot->isEmpty())
            <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $slot }}</div>
        @endif
    </div>
</section>
