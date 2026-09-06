<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('Draft preview') }} · Oceanix</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--ds-surface-canvas)] text-[var(--ds-text-primary)] antialiased">
    <main class="mx-auto max-w-6xl space-y-7 p-5 sm:p-8">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <span class="status-pill status-pill--warning">{{ __('Draft preview') }}</span>
            @if(isset($token))
                <form method="POST" action="{{ route('course-preview.locale', ['token' => $token, 'locale' => app()->getLocale() === 'pt_BR' ? 'en' : 'pt_BR']) }}">
                    @csrf
                    <button class="rounded-xl border border-[var(--ds-border-default)] bg-white px-4 py-2 focus-visible:outline-2 focus-visible:outline-[var(--ds-focus-ring)]" type="submit">{{ app()->getLocale() === 'pt_BR' ? __('English') : __('Portuguese') }}</button>
                </form>
            @endif
        </header>
        @yield('content')
    </main>
</body>
</html>
