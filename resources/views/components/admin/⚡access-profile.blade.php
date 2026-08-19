<?php

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use Livewire\Component;

new class extends Component
{
    public Role $role;

    /** @var list<string> */
    public array $selected = [];

    public function mount(Role $role): void
    {
        $this->authorize('update', $role);

        $this->role = $role;
        $this->selected = $role->permissions()->pluck('key')->all();
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('update', $this->role);

        $before = $this->role->permissions()->pluck('key')->sort()->values()->all();

        // Selecting a dependent permission always persists its prerequisites, so a profile
        // can never grant "publish" without "view".
        $keys = PermissionEnum::withPrerequisites($this->selected);

        $this->role->permissions()->sync(
            Permission::query()->whereIn('key', $keys)->pluck('id')->all()
        );

        $this->selected = $this->role->permissions()->pluck('key')->all();

        $audit->log(
            'access_profile.permissions_changed',
            $this->role,
            ['permissions' => $before],
            ['permissions' => collect($this->selected)->sort()->values()->all()],
        );

        session()->flash('status', __('ui.access_profile_saved'));
    }

    public function with(): array
    {
        return [
            'catalog' => collect(PermissionEnum::cases())->groupBy(fn (PermissionEnum $permission): string => $permission->group()),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.access_profiles')"
        :title="$role->name"
        :description="$role->description">
        <flux:button :href="route('admin.access-profiles')" wire:navigate variant="ghost" size="sm">{{ __('ui.back_to_profiles') }}</flux:button>
        <flux:button wire:click="save" variant="primary" class="admin-primary-action">
            <span wire:loading.remove wire:target="save">{{ __('Save profile') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </flux:button>
    </x-page-hero>

    @if (session('status'))
        <flux:callout variant="success" :heading="session('status')" />
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($catalog as $group => $permissions)
            <section class="detail-card">
                <span class="detail-card-icon"><flux:icon.squares-2x2 class="size-5" /></span>
                <h2 class="detail-card-title">{{ $permissions->first()->groupLabel() }}</h2>

                <div class="mt-4 space-y-2">
                    @foreach ($permissions as $permission)
                        <label class="role-option {{ in_array($permission->value, $selected, true) ? 'is-selected' : '' }}">
                            <input type="checkbox" wire:model="selected" value="{{ $permission->value }}" class="mt-1 size-4 rounded border-[#8e989f] text-[#1c6b84] focus:ring-[#3e8ba3]">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-[#262d33]">{{ $permission->label() }}</span>
                                <span class="mt-0.5 block text-xs text-[#8a9298]"><code>{{ $permission->value }}</code></span>
                                @if ($permission->prerequisites() !== [])
                                    <span class="mt-1 block text-xs text-[#6f797f]">
                                        {{ __('Requires: :list', ['list' => collect($permission->prerequisites())->map(fn ($p) => $p->label())->join(', ')]) }}
                                    </span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</div>
