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

        return ['companies' => $overview->companies($access->authorize()->id)];
    }
};
?>

<div class="space-y-7">
    <x-page-hero :kicker="__('Platform administration')" :title="__('Companies')" :description="__('Create companies and enable Compliance in Account.')" />
    <x-status-message />
    <section class="detail-card divide-y divide-[#e8edef]">
        @forelse ($companies as $company)
            <div class="flex flex-col gap-4 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 break-words"><a href="{{ route('platform.companies.show', ['company' => $company]) }}" wire:navigate class="font-semibold text-[#262d33] hover:text-[#1c6b84]">{{ $company->name }}</a><p class="text-xs text-[#7d878d]">{{ $company->slug }} · {{ $company->public_id }}</p></div>
                <div class="flex flex-wrap items-center gap-3 sm:justify-end">
                    <div class="text-right"><span class="status-pill {{ $company->status === 'active' ? 'status-pill--accent' : '' }}">{{ __($company->status) }}</span><p class="mt-1 text-xs text-[#7d878d]">{{ $company->people_count }} {{ __('people') }}</p></div>
                    @if ($company->workos_organization_id)
                        <div class="text-right"><span class="status-pill status-pill--accent">{{ __('WorkOS synchronized') }}</span><p class="mt-1 font-mono text-[10px] text-[#7d878d]">{{ $company->workos_organization_id }}</p></div>
                    @endif
                    @if ($company->account_linked)
                        <form method="POST" action="{{ route('platform.companies.enter', ['company' => $company]) }}">@csrf<flux:button type="submit" variant="ghost" size="sm">{{ __('Enter company') }}</flux:button></form>
                    @endif
                </div>
            </div>
        @empty
            <x-empty-state icon="building-office-2" :title="__('No companies')" :description="__('Create companies and enable Compliance in Account.')" />
        @endforelse
    </section>
</div>
