<?php

use App\Models\Company;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::guest')] class extends Component
{
    public ?Company $company = null;

    public string $companyCode = '';

    public bool $platform = false;

    public function mount(?Company $company = null): void
    {
        $this->company = $company;
        $this->platform = request()->routeIs('platform.login');
    }

    public function selectCompany(): void
    {
        $this->validate(['companyCode' => ['required', 'string', 'max:100']]);

        $company = Company::query()
            ->where('slug', str($this->companyCode)->slug()->toString())
            ->where('status', 'active')
            ->first();

        if ($company === null) {
            $this->addError('companyCode', __('Company not found.'));

            return;
        }

        $this->redirectRoute('tenant.login', ['company' => $company]);
    }
};
?>

<div class="w-full max-w-md">
    <div class="rounded-[2rem] border border-white/70 bg-white/90 p-7 shadow-[0_30px_80px_-35px_rgba(11,32,44,.45)] backdrop-blur sm:p-10">
        <div class="text-center">
            <img src="{{ asset('images/oceanix-logo.png') }}" alt="Oceanix" class="mx-auto h-auto w-48 sm:w-56">
            <flux:heading size="xl" class="mt-8 !text-[#16222a]">{{ __('ui.login_title') }}</flux:heading>
            <flux:text class="mx-auto mt-2 max-w-xs !text-[#5f6a71]">{{ __('ui.login_subtitle') }}</flux:text>
            @if ($platform)
                <p class="mt-3 text-sm font-bold text-[#1c6b84]">{{ __('Platform administration') }}</p>
            @elseif ($company)
                <p class="mt-3 text-sm font-bold text-[#1c6b84]">{{ $company->name }}</p>
            @endif
        </div>

        @if ($errors->any())
            <flux:callout variant="danger" heading="{{ $errors->first() }}" class="mt-6" />
        @endif

        @if ($platform)
            <flux:button href="{{ route('auth.workos.platform.redirect') }}" variant="primary" class="mt-7 !min-h-12 w-full !rounded-xl !bg-[#16222a] !font-bold hover:!bg-[#22333d]">
                {{ __('Sign in as platform administrator') }}
                <flux:icon.arrow-right class="ml-1 size-4" />
            </flux:button>
            <p class="mt-3 text-center text-xs text-[#7d878d]">{{ __('Access is restricted to authorized platform administrator emails.') }}</p>
        @elseif ($company)
            <flux:button href="{{ route('auth.workos.redirect') }}" variant="primary" class="mt-7 !min-h-12 w-full !rounded-xl !bg-[#16222a] !font-bold hover:!bg-[#22333d]">
                {{ __('ui.login_action') }}
                <flux:icon.arrow-right class="ml-1 size-4" />
            </flux:button>
        @else
            <form wire:submit="selectCompany" class="mt-7 space-y-3">
                <flux:input wire:model="companyCode" label="{{ __('Company code') }}" autocomplete="organization" />
                <flux:button type="submit" variant="primary" class="!min-h-12 w-full !rounded-xl !bg-[#16222a] !font-bold hover:!bg-[#22333d]">
                    <span class="inline-flex items-center gap-2">
                        {{ __('Continue') }}
                        <flux:icon.arrow-right class="size-4" />
                    </span>
                </flux:button>
            </form>
        @endif
        <p class="mt-4 flex items-center justify-center gap-1.5 text-[11px] font-medium text-[#868f95]">
            <flux:icon.lock-closed class="size-3.5" /> {{ __('ui.login_security') }}
        </p>

        @if ($company && Route::has('auth.local'))
            {{-- Visible only in local development, while WorkOS is not configured yet. --}}
            <div class="mt-7 border-t border-[#e5eaed] pt-5 text-center">
                <p class="text-[10px] font-bold uppercase tracking-[.14em] text-[#9aa3a9]">{{ __('ui.local_development') }}</p>
                <flux:button href="{{ route('auth.local') }}" size="sm" class="mt-3 w-full !border !border-[#ccd4d9] !bg-white !font-semibold !text-[#323a40] hover:!bg-[#f2f6f8]">
                    {{ __('ui.local_sign_in', ['email' => config('oceanix.local_auth_email')]) }}
                </flux:button>
            </div>
        @endif
    </div>
</div>
