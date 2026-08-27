<?php

use App\Actions\Modules\CreateModuleDraft;
use App\Actions\SharedContent\ArchiveSharedContent;
use App\Enums\ModuleStatus;
use App\Enums\ModuleVersionStatus;
use App\Models\Module;
use App\Services\Platform\PlatformAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    public Module $module;

    public bool $confirmingArchive = false;

    public string $archiveReason = '';

    public function mount(Module $module, PlatformAccess $access): void
    {
        $access->authorize();
        abort_unless($module->is_shared && $module->company_id === null, 404);
        $this->module = $module;
    }

    public function createDraft(CreateModuleDraft $action, PlatformAccess $access): void
    {
        $access->authorize();
        $source = $this->module->currentPublishedVersion()->firstOrFail();
        $action->handle($source, $access->authorize());
        session()->flash('status', __('Module draft created.'));
    }

    public function archive(ArchiveSharedContent $action, PlatformAccess $access): void
    {
        $account = $access->authorize();
        $this->validate(['archiveReason' => ['required', 'string', 'max:500']]);
        $this->module = $action->handle($this->module, $account, $this->archiveReason);
        $this->reset('confirmingArchive', 'archiveReason');
        session()->flash('status', __('Shared module archived.'));
    }

    public function with(): array
    {
        $status = $this->module->status;

        return [
            'versions' => $this->module->versions()->get(),
            'moduleStatus' => $status instanceof ModuleStatus ? $status : ModuleStatus::from((string) $status),
        ];
    }
};
?>

<div class="space-y-7">
    <x-page-hero :kicker="__('Shared Module')" :title="$module->title" :description="__('Managed by platform and reusable across company courses.')">
        <span class="status-pill {{ $moduleStatus->pillModifier() }}">{{ $moduleStatus->label() }}</span>
        @if ($moduleStatus === ModuleStatus::Archived)
            <span class="text-sm text-[#707a80]">{{ __('New course compositions are blocked.') }}</span>
        @elseif ($versions->contains(fn ($version) => $version->status === ModuleVersionStatus::Draft))
            <flux:button :href="route('platform.shared-modules.editor', ['module' => $module])" wire:navigate variant="primary">{{ __('Edit draft') }}</flux:button>
        @elseif ($module->current_published_version_id)
            <flux:button wire:click="createDraft" wire:loading.attr="disabled" variant="primary">{{ __('Create new version') }}</flux:button>
        @endif
        @if ($moduleStatus !== ModuleStatus::Archived)
            <flux:button wire:click="$set('confirmingArchive', true)" variant="danger">{{ __('Archive shared module') }}</flux:button>
        @endif
    </x-page-hero>
    <x-status-message />
    <div class="grid gap-4 sm:grid-cols-3">
        <section class="metric-card metric-card--slate"><p class="metric-label">{{ __('Code') }}</p><p class="metric-value metric-value--text">{{ $module->code }}</p></section>
        <section class="metric-card metric-card--teal"><p class="metric-label">{{ __('Ownership') }}</p><p class="metric-value metric-value--text">{{ __('Managed by platform') }}</p></section>
        <section class="metric-card metric-card--violet"><p class="metric-label">{{ __('Versions') }}</p><p class="metric-value">{{ $versions->count() }}</p></section>
    </div>
    <section class="detail-card">
        <h2 class="detail-card-title">{{ __('Version history') }}</h2>
        <div class="mt-4 divide-y divide-[#e8edef]">
            @foreach ($versions as $version)
                <div class="flex items-center justify-between py-3"><div><p class="font-semibold">{{ __('Version :number', ['number' => $version->version_number]) }}</p><p class="text-xs text-[#7d878d]">{{ $version->video ? __('Video attached') : __('No video') }}</p></div><span class="status-pill">{{ __($version->status->value) }}</span></div>
            @endforeach
        </div>
    </section>
    <flux:modal wire:model.self="confirmingArchive" class="max-w-lg">
        <form wire:submit="archive" class="space-y-5">
            <div><flux:heading size="lg">{{ __('Archive shared module?') }}</flux:heading><flux:text class="mt-2">{{ __('This module cannot be added to new course compositions. Published courses and existing evidence remain unchanged.') }}</flux:text></div>
            <flux:textarea wire:model="archiveReason" :label="__('Reason')" required />
            <div class="flex justify-end gap-2"><flux:button type="button" wire:click="$set('confirmingArchive', false)" variant="ghost">{{ __('Cancel') }}</flux:button><flux:button type="submit" wire:loading.attr="disabled" variant="danger">{{ __('Archive shared module') }}</flux:button></div>
        </form>
    </flux:modal>
</div>
