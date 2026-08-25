<?php

use App\Services\Platform\PlatformAccess;
use App\Services\Platform\PlatformOverview;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    public function with(PlatformOverview $overview, PlatformAccess $access): array
    {
        $access->authorize();

        return [
            'metrics' => $overview->metrics(),
            'companies' => $overview->companies($access->authorize()->id)->take(8),
        ];
    }
};
?>

<div class="space-y-7">
    <x-page-hero :kicker="__('Platform administration')" :title="__('Global overview')" :description="__('Manage companies and platform administrators.')">
        <flux:button :href="route('platform.companies')" wire:navigate variant="primary">{{ __('Manage companies') }}</flux:button>
    </x-page-hero>
    <x-status-message />
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($metrics as $label => $value)
            <section class="detail-card"><p class="text-xs font-bold uppercase tracking-wider text-[#8a9298]">{{ __(str($label)->headline()->toString()) }}</p><p class="mt-3 text-3xl font-bold">{{ number_format($value) }}</p></section>
        @endforeach
    </div>
    <section class="detail-card">
        <h2 class="detail-card-title">{{ __('Companies') }}</h2>
        <div class="mt-4 divide-y divide-[#e8edef]">
            @foreach ($companies as $company)
                <a
                    href="{{ route('platform.companies.show', ['company' => $company]) }}"
                    wire:navigate
                    wire:key="dashboard-company-{{ $company->id }}"
                    class="group flex items-center justify-between rounded-xl px-2 py-3 transition hover:bg-[#f4f8fa] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1c6b84]"
                    aria-label="{{ __('Open :company', ['company' => $company->name]) }}">
                    <div>
                        <p class="font-semibold text-[#262d33] transition group-hover:text-[#1c6b84]">{{ $company->name }}</p>
                        <p class="text-xs text-[#7d878d]">/c/{{ $company->slug }}</p>
                    </div>
                    <flux:icon.chevron-right class="size-4 text-[#8a9298] transition group-hover:translate-x-0.5 group-hover:text-[#1c6b84]" />
                </a>
            @endforeach
        </div>
    </section>
</div>
