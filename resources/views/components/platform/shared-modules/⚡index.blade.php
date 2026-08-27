<?php

use App\Actions\Modules\CreateModule;
use App\Services\Platform\PlatformAccess;
use App\Services\SharedContent\SharedContentCatalog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    #[Url]
    public string $search = '';
    public string $code = '';
    public string $title = '';
    public string $description = '';

    public function create(CreateModule $action, PlatformAccess $access): void
    {
        $data = $this->validate([
            'code' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $action->handle($access->authorize(), $data['code'], $data['title'], $data['description'] ?: null);
        $this->reset('code', 'title', 'description');
        session()->flash('status', __('Shared module created.'));
    }

    public function with(SharedContentCatalog $catalog, PlatformAccess $access): array
    {
        $access->authorize();

        return ['modules' => $catalog->platformModules($this->search)];
    }
};
?>

<div class="space-y-7">
    <x-page-hero :kicker="__('Platform administration')" :title="__('Shared Modules')" :description="__('Create and manage reusable training modules for every company.')" />
    <x-status-message />
    <div class="grid gap-6 lg:grid-cols-[360px_minmax(0,1fr)]">
        <form wire:submit="create" class="form-panel space-y-4 rounded-[20px] border border-[#dde3e7] p-5">
            <h2 class="font-bold">{{ __('New Shared Module') }}</h2>
            <flux:input wire:model="code" :label="__('Code')" required />
            <flux:input wire:model="title" :label="__('Title')" required />
            <flux:textarea wire:model="description" :label="__('Description')" />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Create Shared Module') }}</flux:button>
        </form>
        <section class="space-y-4">
            <flux:input wire:model.live.debounce.300ms="search" :label="__('Search Shared Modules')" type="search" />
            @if ($modules->isEmpty())
                <x-empty-state :title="__('No Shared Modules found')" :description="__('Create a Shared Module or adjust your search.')" />
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($modules as $module)
                        <a href="{{ route('platform.shared-modules.show', ['module' => $module]) }}" wire:navigate wire:key="shared-module-{{ $module->id }}" class="saas-feature-card">
                            <p class="admin-kicker">{{ __('Shared Module') }}</p>
                            <h2 class="mt-2 font-bold text-[#262d33]">{{ $module->title }}</h2>
                            <p class="mt-1 text-xs text-[#7d878d]">{{ $module->code }} · {{ $module->versions_count }} {{ __('versions') }}</p>
                            <span class="status-pill mt-4">{{ __($module->status->value) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
