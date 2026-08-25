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
    @php($companyIdentities = $platformAccount?->people()->withoutGlobalScope('company')->with('company')->where('status', 'active')->get()->filter(fn ($person) => $person->company?->status === 'active')->unique('company_id')->values() ?? collect())
    <header class="border-b border-[#dce3e7] bg-white">
        <div class="mx-auto flex min-h-20 max-w-[1480px] flex-wrap items-center gap-3 px-5 py-3 sm:gap-7 sm:px-8">
            <a href="{{ route('platform.dashboard') }}" wire:navigate><img src="{{ asset('images/oceanix-logo.png') }}" alt="Oceanix" class="w-32"></a>
            <span class="rounded-full bg-[#16222a] px-3 py-1 text-[11px] font-bold uppercase tracking-[.13em] text-white">{{ __('Platform') }}</span>
            <nav class="ml-auto flex min-w-0 flex-wrap items-center justify-end gap-1 text-sm font-semibold sm:gap-2">
                <a href="{{ route('platform.dashboard') }}" wire:navigate class="hidden rounded-xl px-3 py-2 hover:bg-[#eef3f5] sm:inline-flex">{{ __('Overview') }}</a>
                <a href="{{ route('platform.companies') }}" wire:navigate class="hidden rounded-xl px-3 py-2 hover:bg-[#eef3f5] sm:inline-flex">{{ __('Companies') }}</a>
                <a href="{{ route('platform.users') }}" wire:navigate class="hidden rounded-xl px-3 py-2 hover:bg-[#eef3f5] sm:inline-flex">{{ __('Super admins') }}</a>
                @if ($companyIdentities->count() === 1)
                    @php($identity = $companyIdentities->first())
                    <form method="POST" action="{{ route('platform.companies.enter', ['company' => $identity->company]) }}">
                        @csrf
                        <button type="submit" class="rounded-xl border border-[#dce3e7] px-3 py-2 hover:bg-[#eef3f5]">{{ __('Return to :company', ['company' => $identity->company->name]) }}</button>
                    </form>
                @elseif ($companyIdentities->count() > 1)
                    <details class="group relative">
                        <summary class="cursor-pointer list-none rounded-xl border border-[#dce3e7] px-3 py-2 hover:bg-[#eef3f5]">{{ __('Choose company') }}</summary>
                        <div class="absolute right-0 z-30 mt-2 w-72 rounded-2xl border border-[#dce3e7] bg-white p-2 shadow-xl">
                            <p class="px-3 py-2 text-[10px] font-bold uppercase tracking-[.14em] text-[#8a9298]">{{ __('Your company identities') }}</p>
                            @foreach ($companyIdentities as $identity)
                                <form method="POST" action="{{ route('platform.companies.enter', ['company' => $identity->company]) }}">
                                    @csrf
                                    <button type="submit" class="w-full rounded-xl px-3 py-2 text-left hover:bg-[#eef3f5]">
                                        <span class="block text-sm font-semibold text-[#262d33]">{{ $identity->company->name }}</span>
                                        <span class="block text-xs text-[#7d878d]">{{ $identity->employee_id ?: $identity->email }}</span>
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </details>
                @endif
                <span class="hidden text-xs text-[#667178] sm:inline">{{ $platformAccount?->email }}</span>
                <form method="POST" action="{{ route('platform.logout') }}">
                    @csrf
                    <flux:button type="submit" variant="ghost" size="sm" icon="arrow-right-start-on-rectangle">{{ __('Sign out') }}</flux:button>
                </form>
            </nav>
        </div>
    </header>
    <main class="mx-auto w-full max-w-[1480px] px-5 py-9 sm:px-8">{{ $slot }}</main>
    <x-status-toast />
    @fluxScripts
</body>
</html>
