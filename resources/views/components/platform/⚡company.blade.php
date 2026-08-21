<?php

use App\Actions\Platform\ChangeCompanyStatus;
use App\Actions\Platform\GrantPlatformCompanyAccess;
use App\Actions\Platform\InviteCompanyAdministrator;
use App\Actions\Platform\ProvisionCompanyInWorkos;
use App\Models\Company;
use App\Services\Platform\PlatformAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    public Company $company;

    public bool $hasCompanyAccess = false;

    public string $administratorName = '';

    public string $administratorEmail = '';

    public function mount(Company $company, PlatformAccess $access): void
    {
        $access->authorize();
        $this->company = $company;
        $this->refreshCompanyAccess($access);
    }

    public function changeStatus(string $status, ChangeCompanyStatus $action): void
    {
        $this->company = $action->handle($this->company, $status);
        session()->flash('status', __('Company status updated.'));
    }

    public function provisionWorkos(ProvisionCompanyInWorkos $action): void
    {
        try {
            $this->company = $action->handle($this->company);
            session()->flash('status', __('Company synchronized with WorkOS.'));
        } catch (\RuntimeException $exception) {
            $this->addError('workos', $exception->getMessage());
        }
    }

    public function grantMyAccess(GrantPlatformCompanyAccess $action, PlatformAccess $access): void
    {
        $action->handle($this->company);
        $this->refreshCompanyAccess($access);
        session()->flash('status', __('Your company administrator access was created.'));
    }

    public function inviteAdministrator(InviteCompanyAdministrator $action): void
    {
        $data = $this->validate([
            'administratorName' => ['required', 'string', 'max:255'],
            'administratorEmail' => ['required', 'email', 'max:255'],
        ]);

        try {
            $result = $action->handle($this->company, $data['administratorName'], $data['administratorEmail']);
            $this->reset('administratorName', 'administratorEmail');
            session()->flash('status', $result['invitation_sent']
                ? __('Company administrator invited.')
                : __('Existing WorkOS user added as a company administrator.'));
        } catch (\RuntimeException $exception) {
            $this->addError('administratorEmail', $exception->getMessage());
        }
    }

    private function refreshCompanyAccess(PlatformAccess $access): void
    {
        $this->hasCompanyAccess = $this->company->users()
            ->withoutGlobalScope('company')
            ->where('account_id', $access->authorize()->id)
            ->exists();
    }
};
?>

<div class="space-y-7">
    <x-page-hero :kicker="__('Platform administration')" :title="$company->name" :description="__('Inspect identity integration and control tenant access.')">
        <span class="status-pill {{ $company->status === 'active' ? 'status-pill--positive' : 'status-pill--warning' }}">{{ __(ucfirst($company->status)) }}</span>
        <flux:button :href="route('platform.companies')" wire:navigate variant="ghost">{{ __('Back to companies') }}</flux:button>
    </x-page-hero>

    @if (session('status')) <flux:callout variant="success" :heading="session('status')" /> @endif
    @error('workos') <flux:callout variant="danger" :heading="$message" /> @enderror

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="detail-card space-y-4">
            <h2 class="detail-card-title">{{ __('Company details') }}</h2>
            <dl class="space-y-3 text-sm">
                <div><dt class="text-[#8a9298]">{{ __('URL code') }}</dt><dd class="font-medium">{{ $company->slug }}</dd></div>
                <div><dt class="text-[#8a9298]">{{ __('External ID') }}</dt><dd class="font-mono text-xs">{{ $company->public_id }}</dd></div>
                <div><dt class="text-[#8a9298]">{{ __('WorkOS organization') }}</dt><dd class="font-mono text-xs">{{ $company->workos_organization_id ?: '—' }}</dd></div>
            </dl>
            <flux:button wire:click="provisionWorkos" wire:loading.attr="disabled" variant="ghost">{{ $company->workos_organization_id ? __('Synchronize WorkOS') : __('Provision in WorkOS') }}</flux:button>
        </section>

        <section class="detail-card space-y-4">
            <h2 class="detail-card-title">{{ __('Tenant lifecycle') }}</h2>
            <p class="text-sm text-[#5f6a71]">{{ __('Suspending a company blocks its tenant login and operational routes without deleting its records.') }}</p>
            @if ($company->status === 'active')
                <flux:button wire:click="changeStatus('suspended')" wire:confirm="{{ __('Suspend this company?') }}" variant="danger">{{ __('Suspend company') }}</flux:button>
            @else
                <flux:button wire:click="changeStatus('active')" variant="primary">{{ __('Activate company') }}</flux:button>
            @endif
            @if ($hasCompanyAccess)
                <form method="POST" action="{{ route('platform.companies.enter', ['company' => $company]) }}">
                    @csrf
                    <flux:button type="submit" variant="primary">{{ __('Enter company') }}</flux:button>
                </form>
            @else
                <flux:button wire:click="grantMyAccess" wire:loading.attr="disabled" variant="primary">{{ __('Create my administrator access') }}</flux:button>
            @endif
        </section>
    </div>

    <section class="detail-card max-w-2xl space-y-4">
        <div>
            <h2 class="detail-card-title">{{ __('Invite a company administrator') }}</h2>
            <p class="mt-1 text-sm text-[#5f6a71]">{{ __('The person will receive a WorkOS invitation and join this company with the protected Administrator profile.') }}</p>
        </div>
        <form wire:submit="inviteAdministrator" class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="administratorName" :label="__('Name')" required />
            <flux:input wire:model="administratorEmail" type="email" :label="__('Email')" required />
            <div class="sm:col-span-2">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Create administrator and send invitation') }}</flux:button>
            </div>
        </form>
    </section>
</div>
