<?php

use App\Actions\Platform\CreateCompany;
use App\Actions\Platform\ProvisionCompanyInWorkos;
use App\Models\Company;
use App\Services\Platform\PlatformOverview;
use App\Services\Platform\PlatformAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    public string $name = '';
    public string $slug = '';

    public function create(CreateCompany $action, PlatformAccess $access): void
    {
        $data = $this->validate(['name' => ['required', 'string', 'max:255'], 'slug' => ['nullable', 'alpha_dash', 'max:255', 'unique:companies,slug']]);
        $action->handle($data['name'], $data['slug'] ?: null, $access->authorize());
        $this->reset('name', 'slug');
        session()->flash('status', __('Company created.'));
    }

    public function with(PlatformOverview $overview, PlatformAccess $access): array
    {
        $access->authorize();

        return ['companies' => $overview->companies($access->authorize()->id)];
    }

    public function provisionWorkos(int $companyId, ProvisionCompanyInWorkos $action): void
    {
        try {
            $action->handle(Company::query()->findOrFail($companyId));
            session()->flash('status', __('Company synchronized with WorkOS.'));
        } catch (\RuntimeException $exception) {
            $this->addError('workos', $exception->getMessage());
        }
    }
};
?>

<div class="space-y-7">
    <x-page-hero :kicker="__('Platform administration')" :title="__('Companies')" :description="__('Create and inspect tenant workspaces.')" />
    @if (session('status')) <flux:callout variant="success" :heading="session('status')" /> @endif
    @error('workos') <flux:callout variant="danger" :heading="$message" /> @enderror
    <div class="grid gap-6 lg:grid-cols-[380px_minmax(0,1fr)]">
        <form wire:submit="create" class="form-panel space-y-4 rounded-[20px] border border-[#dde3e7] p-5">
            <h2 class="font-bold">{{ __('New company') }}</h2>
            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input wire:model="slug" :label="__('URL code')" :description="__('Leave blank to generate it from the name.')" />
            <flux:button type="submit" variant="primary">{{ __('Create company') }}</flux:button>
        </form>
        <section class="detail-card divide-y divide-[#e8edef]">
            @foreach ($companies as $company)
                <div class="flex flex-col gap-4 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                    <div><p class="font-semibold">{{ $company->name }}</p><p class="text-xs text-[#7d878d]">{{ $company->slug }} · {{ $company->public_id }}</p></div>
                    <div class="flex items-center gap-3 sm:justify-end">
                        <div class="text-right"><span class="status-pill {{ $company->status === 'active' ? 'status-pill--accent' : '' }}">{{ __($company->status) }}</span><p class="mt-1 text-xs text-[#7d878d]">{{ $company->people_count }} {{ __('people') }}</p></div>
                        @if ($company->workos_organization_id)
                            <div class="text-right"><span class="status-pill status-pill--accent">{{ __('WorkOS synchronized') }}</span><p class="mt-1 font-mono text-[10px] text-[#7d878d]">{{ $company->workos_organization_id }}</p></div>
                            <flux:button wire:click="provisionWorkos({{ $company->id }})" wire:loading.attr="disabled" variant="ghost" size="sm">{{ __('Synchronize') }}</flux:button>
                        @else
                            <flux:button wire:click="provisionWorkos({{ $company->id }})" wire:loading.attr="disabled" variant="primary" size="sm">{{ __('Provision in WorkOS') }}</flux:button>
                        @endif
                        @if ($company->account_linked)
                            <form method="POST" action="{{ route('platform.companies.enter', ['company' => $company]) }}">@csrf<flux:button type="submit" variant="ghost" size="sm">{{ __('Enter company') }}</flux:button></form>
                        @endif
                    </div>
                </div>
            @endforeach
        </section>
    </div>
</div>
