<?php

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Role assignment is administrator-only; the route already enforces it. */
    public function toggleRole(int $userId, int $roleId, AuditLogger $audit): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $user = User::query()->findOrFail($userId);
        $role = Role::query()->findOrFail($roleId);

        $before = $user->roles()->pluck('key')->all();

        $user->roles()->toggle($role);

        $audit->log(
            'user.roles_changed',
            $user,
            ['roles' => $before],
            ['roles' => $user->roles()->pluck('key')->all()],
        );
    }

    public function with(): array
    {
        $query = User::query()->with('roles');

        if ($this->search !== '') {
            $term = '%'.strtolower($this->search).'%';
            $query->where(fn ($scoped) => $scoped
                ->whereRaw('lower(name) like ?', [$term])
                ->orWhereRaw('lower(email) like ?', [$term]));
        }

        return [
            'users' => $query->orderBy('name')->paginate(25),
            'roles' => Role::query()->active()->orderBy('name')->get(),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.administration')"
        :title="__('ui.users')"
        :description="__('ui.users_page_description')">
        <span class="status-pill status-pill--accent">{{ trans_choice('ui.results_count', $users->total(), ['count' => $users->total()]) }}</span>
    </x-page-hero>

    <div class="form-panel rounded-[20px] border border-[#dde3e7] p-4 sm:p-5">
        <flux:input wire:model.live.debounce.400ms="search" class="admin-control" icon="magnifying-glass" :label="__('Search')" :placeholder="__('Name or email')" />
    </div>

    <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7] shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]">
        <table class="w-full text-left text-sm">
            <thead>
                <tr>
                    <th>{{ __('User') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Identity provider') }}</th>
                    <th>{{ __('Access profiles') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr class="border-t">
                        <td>
                            <a href="{{ route('people.show', ['company' => app(App\Tenancy\TenantContext::class)->get(), 'user' => $user]) }}" wire:navigate class="font-semibold text-[#262d33] hover:text-[#1c6b84]">{{ $user->name }}</a>
                            <span class="block text-xs text-[#8a9298]">{{ $user->email }}</span>
                        </td>
                        <td><span class="status-pill {{ $user->status->pillModifier() }}">{{ $user->status->label() }}</span></td>
                        <td class="text-[#5f6a71]">{{ $user->provider ? ucfirst($user->provider) : __('Not linked') }}</td>
                        <td>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($roles as $role)
                                    @php($assigned = $user->roles->contains($role))
                                    <button type="button"
                                        wire:click="toggleRole({{ $user->id }}, {{ $role->id }})"
                                        class="status-pill {{ $assigned ? 'status-pill--accent' : 'status-pill--neutral' }} transition hover:opacity-80"
                                        aria-pressed="{{ $assigned ? 'true' : 'false' }}">
                                        {{ $role->name }}
                                    </button>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div>{{ $users->links() }}</div>
</div>
