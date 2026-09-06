<?php

use App\Actions\Modules\CreateModuleDraft;
use App\Actions\Modules\DiscardModuleDraft;
use App\Actions\SharedContent\ArchiveSharedContent;
use App\Enums\ModuleStatus;
use App\Enums\ModuleVersionStatus;
use App\Enums\PlatformPermission as Permission;
use App\Models\Module;
use App\Services\Modules\ModuleStatusPresentation;
use App\Services\Platform\PlatformAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    public Module $module;

    public bool $confirmingArchive = false;

    public string $archiveReason = '';

    public bool $confirmingDiscard = false;

    public string $discardReason = '';

    #[Locked]
    public ?int $discardVersionId = null;

    #[Locked]
    public string $discardRevision = '';

    public function confirmDiscard(DiscardModuleDraft $action, PlatformAccess $access): void
    {
        $access->authorizePermission(Permission::SharedModulesDiscardDraft);
        $draft = $this->module->versions()->where('status', ModuleVersionStatus::Draft->value)->firstOrFail();
        abort_if($draft->lineage_archived_at !== null, 404);
        $this->discardVersionId = $draft->id;
        $this->discardRevision = $action->revision($draft);
        $this->reset('discardReason');
        $this->resetValidation();
        $this->confirmingDiscard = true;
    }

    public function discardDraft(DiscardModuleDraft $action, PlatformAccess $access): void
    {
        $actor = $access->authorizePermission(Permission::SharedModulesDiscardDraft);
        $this->validate(['discardReason' => ['required', 'string', 'max:500']]);
        $draft = $this->module->versions()->whereKey($this->discardVersionId)->firstOrFail();
        $action->handle($draft, $actor, $this->discardReason, $this->discardRevision);
        $this->module = $this->module->fresh();
        $this->reset('confirmingDiscard', 'discardReason', 'discardVersionId', 'discardRevision');
        session()->flash('status', __('Module draft discarded. Published versions and history remain available.'));
    }

    public function mount(Module $module, PlatformAccess $access): void
    {
        $access->authorizePermission(Permission::SharedModulesView);
        abort_unless($module->is_shared && $module->company_id === null, 404);
        $this->module = $module;
    }

    public function createDraft(CreateModuleDraft $action, PlatformAccess $access): void
    {
        $access->authorizePermission(Permission::SharedModulesUpdate);
        abort_if($this->module->lineage_archived_at !== null, 404);
        $source = $this->module->currentPublishedVersion()->firstOrFail();
        $action->handle($source, $access->authorizePermission(Permission::SharedModulesUpdate));
        session()->flash('status', __('Module draft created.'));
    }

    public function archive(ArchiveSharedContent $action, PlatformAccess $access): void
    {
        $account = $access->authorizePermission(Permission::SharedModulesArchive);
        $this->validate(['archiveReason' => ['required', 'string', 'max:500']]);
        $this->module = $action->handle($this->module, $account, $this->archiveReason);
        $this->reset('confirmingArchive', 'archiveReason');
        session()->flash('status', __('Shared module archived.'));
    }

    public function with(ModuleStatusPresentation $statusPresentation, PlatformAccess $access): array
    {
        $access->authorizePermission(Permission::SharedModulesView);

        return [
            'versions' => $this->module->versions()->get(),
            'moduleStatus' => $statusPresentation->for(
                $this->module->lineage_archived_at !== null ? ModuleStatus::Archived : $this->module->status,
            ),
        ];
    }
};
?>

<div class="space-y-7">
    <x-page-hero :kicker="__('Shared Module')" :title="$module->title" :description="__('Managed by platform and reusable across company courses.')">
        <span class="status-pill {{ $moduleStatus['modifier'] }}">{{ $moduleStatus['label'] }}</span>
        @if ($moduleStatus['is_archived'])
            <span class="text-sm text-[#707a80]">{{ __('New course compositions are blocked.') }}</span>
        @elseif ($versions->contains(fn ($version) => $version->status === ModuleVersionStatus::Draft))
            <flux:button :href="route('platform.shared-modules.editor', ['module' => $module])" wire:navigate variant="primary">{{ __('Edit draft') }}</flux:button>
            <flux:button wire:click="confirmDiscard" wire:loading.attr="disabled" variant="danger">{{ __('Discard draft') }}</flux:button>
        @elseif ($module->currentPublishedVersion()->exists())
            <flux:button wire:click="createDraft" wire:loading.attr="disabled" variant="primary">{{ __('Create new version') }}</flux:button>
        @endif
        @if (! $moduleStatus['is_archived'])
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
                <div class="flex items-center justify-between py-3"><p class="font-semibold">{{ __('Version :number', ['number' => $version->version_number]) }}</p><span class="status-pill">{{ __(ucfirst($version->status->value)) }}</span></div>
            @endforeach
        </div>
    </section>
    <flux:modal wire:model.self="confirmingDiscard" :dismissible="false" class="max-w-lg">
        <form wire:submit="discardDraft" class="space-y-5">
            <div><flux:heading size="lg">{{ __('Discard module draft?') }}</flux:heading><flux:text class="mt-2">{{ __('This draft will no longer be editable. Published versions and the draft history will be preserved. Remove this draft from any course composition before discarding it.') }}</flux:text></div>
            <flux:textarea wire:model="discardReason" :label="__('Reason')" required />
            @error('discard') <flux:callout variant="danger" :heading="$message" /> @enderror
            <p wire:loading wire:target="discardDraft" role="status" class="text-sm">{{ __('Discarding draft…') }}</p>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:button type="button" wire:click="$set('confirmingDiscard', false)" wire:loading.attr="disabled" wire:target="discardDraft" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" wire:loading.attr="disabled" wire:target="discardDraft" variant="danger">{{ __('Discard draft') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    <flux:modal wire:model.self="confirmingArchive" class="max-w-lg">
        <form wire:submit="archive" class="space-y-5">
            <div><flux:heading size="lg">{{ __('Archive shared module?') }}</flux:heading><flux:text class="mt-2">{{ __('This module cannot be added to new course compositions. Published courses and existing evidence remain unchanged.') }}</flux:text></div>
            <flux:textarea wire:model="archiveReason" :label="__('Reason')" required />
            <div class="flex justify-end gap-2"><flux:button type="button" wire:click="$set('confirmingArchive', false)" variant="ghost">{{ __('Cancel') }}</flux:button><flux:button type="submit" wire:loading.attr="disabled" variant="danger">{{ __('Archive shared module') }}</flux:button></div>
        </form>
    </flux:modal>
</div>
