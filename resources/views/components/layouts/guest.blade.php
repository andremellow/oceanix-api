<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>

    <link rel="icon" href="{{ asset('images/oceanix-mark.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-[#e9f0f4] text-[#16222a] antialiased">
    <div class="fixed right-4 top-4 z-10 sm:right-7 sm:top-6">
        <form method="POST" action="{{ route('locale.update', app()->getLocale() === 'pt_BR' ? 'en' : 'pt_BR') }}">
            @csrf
            <button type="submit" class="grid size-11 place-items-center rounded-full border border-white/80 bg-white/85 text-[#2e4954] shadow-sm backdrop-blur transition hover:-translate-y-0.5 hover:bg-white" aria-label="{{ __('ui.switch_language') }}" title="{{ __('ui.switch_language') }}">
                <flux:icon.language class="size-5" />
            </button>
        </form>
    </div>
    <div class="relative flex min-h-screen flex-col items-center justify-center overflow-hidden p-5 sm:p-8">
        <div class="pointer-events-none absolute -left-24 -top-24 size-80 rounded-full bg-[#a9d8e8]/55 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -right-20 size-96 rounded-full bg-[#b3cbd6]/45 blur-3xl"></div>
        {{ $slot }}
    </div>

    @fluxScripts
</body>
</html>
