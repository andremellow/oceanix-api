<?php

use App\Actions\Platform\ChangeCompanyStatus;
use App\Actions\Platform\GrantPlatformCompanyAccess;
use App\Actions\Platform\InviteCompanyAdministrator;
use App\Actions\Platform\ProvisionCompanyInWorkos;
use App\Models\Company;
use App\Models\User;
use App\Services\Platform\PlatformAccess;
use Illuminate\Database\Eloquent\Builder;
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

    public function with(): array
    {
        return [
            'administrators' => User::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $this->company->id)
                ->whereHas('roles', fn (Builder $query): Builder => $query
                    ->withoutGlobalScope('company')
                    ->where('roles.company_id', $this->company->id)
                    ->where('key', 'admin'))
                ->orderBy('name')
                ->orderBy('email')
                ->get(),
        ];
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

    <x-status-message />
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

    <section class="detail-card space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="detail-card-title">{{ __('Company administrators') }}</h2>
                <p class="mt-1 text-sm text-[#5f6a71]">{{ __('People with the protected Administrator profile in this company.') }}</p>
            </div>
            <span class="status-pill status-pill--neutral">{{ trans_choice('{0} No administrators|{1} :count administrator|[2,*] :count administrators', $administrators->count(), ['count' => $administrators->count()]) }}</span>
        </div>

        @if ($administrators->isEmpty())
            <x-empty-state icon="users" :title="__('No company administrators')" :description="__('Invite the first administrator using the form below.')" />
        @else
            <div class="overflow-x-auto rounded-[16px] border border-[#dde3e7]">
                <table class="min-w-full divide-y divide-[#e5eaed] text-left text-sm">
                    <thead class="bg-[#f8fafb] text-[10px] font-bold uppercase tracking-[.12em] text-[#7a858b]">
                        <tr><th class="px-4 py-3">{{ __('Name') }}</th><th class="px-4 py-3">{{ __('Email') }}</th><th class="px-4 py-3">{{ __('Status') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-[#edf0f2] bg-white">
                        @foreach ($administrators as $administrator)
                            <tr wire:key="company-administrator-{{ $administrator->id }}">
                                <td class="px-4 py-3 font-semibold text-[#262d33]">{{ $administrator->name }}</td>
                                <td class="px-4 py-3 text-[#5f6a71]">{{ $administrator->email }}</td>
                                <td class="px-4 py-3"><span class="status-pill {{ $administrator->status->pillModifier() }}">{{ $administrator->status->label() }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

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
