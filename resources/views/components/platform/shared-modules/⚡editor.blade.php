<?php

use App\Actions\Modules\PublishModuleVersion;
use App\Enums\ModuleVersionStatus;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Services\Modules\ModulePropagationImpact;
use App\Services\Modules\ModuleVersionValidator;
use App\Services\Platform\PlatformAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    public Module $module;
    public ModuleVersion $version;
    public bool $restartInProgress = false;

    public function mount(Module $module, PlatformAccess $access): void
    {
        $access->authorize();
        abort_unless($module->is_shared && $module->company_id === null, 404);
        $this->module = $module;
        $this->version = $module->versions()->where('status', ModuleVersionStatus::Draft->value)->firstOrFail();
    }

    public function publish(PublishModuleVersion $action, PlatformAccess $access): void
    {
        $action->handle($this->version, $access->authorize(), $this->restartInProgress);
        session()->flash('status', __('Shared module published.'));
        $this->redirectRoute('platform.shared-modules.show', ['module' => $this->module], navigate: true);
    }

    public function with(ModulePropagationImpact $impact, ModuleVersionValidator $validator): array
    {
        return ['impact' => $impact->summarize($this->version), 'problems' => $validator->problems($this->version)];
    }
};
?>

<div class="space-y-7">
    <x-page-hero :kicker="__('Shared Module draft')" :title="$version->title" :description="__('Review publication readiness and its impact before publishing.')" />
    <x-status-message />
    @if ($problems)
        <flux:callout variant="danger" :heading="__('This version is not ready to publish.')"><ul class="mt-2 list-disc pl-5">@foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul></flux:callout>
    @endif
    <section class="detail-card space-y-5">
        <div><h2 class="detail-card-title">{{ __('Publication impact') }}</h2><p class="mt-1 text-sm text-[#707a80]">{{ __('Publishing updates future training while preserving completed history.') }}</p></div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="metric-card metric-card--slate"><p class="metric-label">{{ __('Affected courses') }}</p><p class="metric-value">{{ $impact['affected_courses'] }}</p></div>
            <div class="metric-card metric-card--teal"><p class="metric-label">{{ __('Not started') }}</p><p class="metric-value">{{ $impact['not_started_assignments'] }}</p></div>
            <div class="metric-card metric-card--amber"><p class="metric-label">{{ __('In progress') }}</p><p class="metric-value">{{ $impact['in_progress_assignments'] }}</p></div>
        </div>
        <flux:checkbox wire:model="restartInProgress" :label="__('Restart in-progress assignments')" :description="__('When selected, existing progress is not transferred to the new assignment.')" />
        <flux:button wire:click="publish" wire:loading.attr="disabled" variant="primary" :disabled="count($problems) > 0">{{ __('Publish Shared Module') }}</flux:button>
        <p wire:loading wire:target="publish" role="status" class="text-sm text-[#5f6a71]">{{ __('Publishing and preparing course updates…') }}</p>
    </section>
</div>
