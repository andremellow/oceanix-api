<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('ui.verify_title') }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('images/oceanix-mark.png') }}" type="image/png">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-[#e9f0f4] text-[#16222a] antialiased">
    <div class="relative flex min-h-screen flex-col items-center justify-center overflow-hidden p-5 sm:p-8">
        <div class="pointer-events-none absolute -left-24 -top-24 size-80 rounded-full bg-[#a9d8e8]/55 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -right-20 size-96 rounded-full bg-[#b3cbd6]/45 blur-3xl"></div>

        <div class="relative w-full max-w-lg">
            <div class="rounded-[2rem] border border-white/70 bg-white/95 p-7 shadow-[0_30px_80px_-35px_rgba(11,32,44,.45)] backdrop-blur sm:p-10">
                <img src="{{ asset('images/oceanix-logo.png') }}" alt="Oceanix" class="mx-auto h-auto w-44">

                @if ($certificate === null)
                    <div class="mt-8 text-center">
                        <span class="mx-auto grid size-12 place-items-center rounded-2xl bg-[#f4f1e4] text-[#9a6a1a]">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-6"><path d="M12 9v4m0 4h.01M10.3 3.9 2.6 17.1A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.9L13.7 3.9a2 2 0 0 0-3.4 0Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <h1 class="mt-5 text-xl font-bold tracking-tight">{{ __('ui.verify_not_found') }}</h1>
                        <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-[#5f6a71]">{{ __('ui.verify_not_found_help') }}</p>
                    </div>
                @else
                    <div class="mt-8 text-center">
                        @if ($certificate->isValid())
                            <span class="mx-auto grid size-12 place-items-center rounded-2xl bg-[#e6f3ea] text-[#2f7d4f]">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-6"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <h1 class="mt-5 text-xl font-bold tracking-tight">{{ __('ui.verify_valid') }}</h1>
                        @else
                            <span class="mx-auto grid size-12 place-items-center rounded-2xl bg-[#fbe9e9] text-[#b23a3a]">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-6"><path d="M6 6l12 12M18 6 6 18" stroke-linecap="round"/></svg>
                            </span>
                            <h1 class="mt-5 text-xl font-bold tracking-tight">
                                {{ $certificate->isRevoked() ? __('ui.verify_revoked') : __('ui.verify_expired') }}
                            </h1>
                        @endif
                        <p class="mt-2 text-sm text-[#5f6a71]">{{ __('Certificate :number', ['number' => $certificate->certificate_number]) }}</p>
                    </div>

                    <dl class="mt-8 space-y-3 border-t border-[#e2e7ea] pt-6 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-[#8a9298]">{{ __('Holder') }}</dt>
                            <dd class="text-right font-bold text-[#262d33]">{{ $certificate->user->name }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-[#8a9298]">{{ __('Course') }}</dt>
                            <dd class="text-right font-bold text-[#262d33]">{{ $certificate->course->title }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-[#8a9298]">{{ __('Issued') }}</dt>
                            <dd class="text-right font-bold text-[#262d33]">{{ $certificate->issued_at->locale(app()->getLocale())->translatedFormat('M j, Y') }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-[#8a9298]">{{ __('Valid until') }}</dt>
                            <dd class="text-right font-bold text-[#262d33]">{{ $certificate->expires_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? __('No expiry') }}</dd>
                        </div>
                    </dl>
                @endif
            </div>

            <p class="mt-5 text-center text-xs text-[#7c878e]">{{ __('ui.verify_footer') }}</p>
        </div>
    </div>
</body>
</html>
