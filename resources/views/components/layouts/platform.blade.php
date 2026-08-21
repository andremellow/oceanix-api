<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('Platform administration') }}</title>
    <link rel="icon" href="{{ asset('images/oceanix-mark.png') }}" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-[#f2f5f7] text-[#1f262b] antialiased">
    @php($platformAccount = app(\App\Services\Platform\PlatformAccess::class)->account())
    <header class="border-b border-[#dce3e7] bg-white">
        <div class="mx-auto flex h-20 max-w-[1480px] items-center gap-7 px-5 sm:px-8">
            <a href="{{ route('platform.dashboard') }}" wire:navigate><img src="{{ asset('images/oceanix-logo.png') }}" alt="Oceanix" class="w-32"></a>
            <span class="rounded-full bg-[#16222a] px-3 py-1 text-[11px] font-bold uppercase tracking-[.13em] text-white">{{ __('Platform') }}</span>
            <nav class="ml-auto flex items-center gap-2 text-sm font-semibold">
                <a href="{{ route('platform.dashboard') }}" wire:navigate class="rounded-xl px-3 py-2 hover:bg-[#eef3f5]">{{ __('Overview') }}</a>
                <a href="{{ route('platform.companies') }}" wire:navigate class="rounded-xl px-3 py-2 hover:bg-[#eef3f5]">{{ __('Companies') }}</a>
                @if (auth()->check() && app(\App\Tenancy\TenantContext::class)->get())
                    <a href="{{ route('dashboard') }}" class="rounded-xl border border-[#dce3e7] px-3 py-2">{{ __('Return to company') }}</a>
                @endif
                <span class="hidden text-xs text-[#667178] sm:inline">{{ $platformAccount?->email }}</span>
                <form method="POST" action="{{ route('platform.logout') }}">@csrf<flux:button type="submit" variant="ghost" size="sm">{{ __('Log out') }}</flux:button></form>
            </nav>
        </div>
    </header>
    <main class="mx-auto w-full max-w-[1480px] px-5 py-9 sm:px-8">{{ $slot }}</main>
    @fluxScripts
</body>
</html>
