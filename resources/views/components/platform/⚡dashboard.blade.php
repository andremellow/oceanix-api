<?php

use App\Services\Platform\PlatformOverview;
use App\Services\Platform\PlatformAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    public function with(PlatformOverview $overview, PlatformAccess $access): array
    {
        $access->authorize();

        return ['metrics' => $overview->metrics(), 'companies' => $overview->companies($access->authorize()->id)->take(8)];
    }
};
?>

<div class="space-y-7">
    <x-page-hero :kicker="__('Platform administration')" :title="__('Global overview')" :description="__('Manage companies and review the operation across every tenant.')">
        <flux:button :href="route('platform.companies')" wire:navigate variant="primary">{{ __('Manage companies') }}</flux:button>
    </x-page-hero>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($metrics as $label => $value)
            <section class="detail-card"><p class="text-xs font-bold uppercase tracking-wider text-[#8a9298]">{{ __(str($label)->headline()->toString()) }}</p><p class="mt-3 text-3xl font-bold">{{ number_format($value) }}</p></section>
        @endforeach
    </div>
    <section class="detail-card">
        <h2 class="detail-card-title">{{ __('Companies') }}</h2>
        <div class="mt-4 divide-y divide-[#e8edef]">
            @foreach ($companies as $company)
                <div class="flex items-center justify-between py-3"><div><p class="font-semibold">{{ $company->name }}</p><p class="text-xs text-[#7d878d]">/c/{{ $company->slug }}</p></div><span class="status-pill status-pill--accent">{{ $company->people_count }} {{ __('people') }}</span></div>
            @endforeach
        </div>
    </section>
</div>
