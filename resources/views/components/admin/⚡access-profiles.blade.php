<?php

use App\Models\Role;
use App\Services\Audit\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public string $description = '';

    public function save(AuditLogger $audit): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('roles', 'name')],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $role = Role::query()->create([
            'key' => str($validated['name'])->slug()->toString(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?: null,
        ]);

        $audit->log('access_profile.created', $role, after: $validated);

        $this->reset(['name', 'description']);

        $this->redirect(route('admin.access-profiles.show', ['role' => $role]), navigate: true);
    }

    public function with(): array
    {
        return [
            'roles' => Role::query()
                ->withCount(['permissions', 'users'])
                ->orderBy('name')
                ->get(),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.administration')"
        :title="__('ui.access_profiles')"
        :description="__('ui.access_profiles_page_description')" />

    <div class="form-panel rounded-[20px] border border-[#dde3e7] p-5 sm:p-6">
        <h2 class="text-base font-bold text-[#262d33]">{{ __('New access profile') }}</h2>
        <form wire:submit="save" class="mt-4 grid gap-3 sm:grid-cols-[240px_minmax(0,1fr)_auto] sm:items-end">
            <flux:input wire:model="name" class="admin-control" :label="__('Name')" :placeholder="__('Training coordinator')" />
            <flux:input wire:model="description" class="admin-control" :label="__('Description')" :placeholder="__('What this profile is for')" />
            <flux:button type="submit" variant="primary" class="admin-primary-action">{{ __('Create profile') }}</flux:button>
        </form>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($roles as $role)
            <a href="{{ route('admin.access-profiles.show', ['role' => $role]) }}" wire:navigate class="saas-feature-card group">
                <div class="flex items-start justify-between gap-3">
                    <span class="saas-feature-icon bg-[#e4f0f5] text-[#1c6b84]"><flux:icon.key class="size-5" /></span>
                    @if ($role->is_protected)
                        <span class="status-pill status-pill--neutral">{{ __('Protected') }}</span>
                    @endif
                </div>
                <span class="mt-5 block text-base font-bold">{{ $role->name }}</span>
                <span class="mt-1.5 block text-sm leading-5 text-[#778188]">{{ $role->description ?: __('No description provided') }}</span>
                <div class="mt-5 flex items-center justify-between border-t border-[#eef1f4] pt-4 text-xs">
                    <span class="text-[#8a9298]">{{ trans_choice('ui.permissions_count', $role->permissions_count, ['count' => $role->permissions_count]) }}</span>
                    <span class="font-bold text-[#1c6b84]">{{ trans_choice('ui.members_count', $role->users_count, ['count' => $role->users_count]) }}</span>
                </div>
            </a>
        @endforeach
    </div>
</div>
