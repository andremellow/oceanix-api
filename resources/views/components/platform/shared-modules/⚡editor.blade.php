<?php

use App\Actions\Modules\PublishModuleVersion;
use App\Actions\Videos\RequestVideoUpload;
use App\Enums\ModuleVersionStatus;
use App\Enums\VideoStatus;
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
    public string $title = '';
    public string $description = '';
    public string $contentMarkdown = '';
    public int $minimumWatchPercentage = 90;
    public int $passingScore = 70;
    public bool $restartInProgress = false;

    public function mount(Module $module, PlatformAccess $access): void
    {
        $access->authorize();
        abort_unless($module->is_shared && $module->company_id === null, 404);
        $this->module = $module;
        $this->version = $module->versions()->where('status', ModuleVersionStatus::Draft->value)->firstOrFail();
        $this->title = $this->version->title;
        $this->description = (string) $this->version->description;
        $this->contentMarkdown = (string) $this->version->content_markdown;
        $this->minimumWatchPercentage = $this->version->minimum_watch_percentage;
        $this->passingScore = $this->version->passing_score;
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['title', 'description', 'contentMarkdown', 'minimumWatchPercentage', 'passingScore'], true)) return;
        $this->validate([
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'],
            'contentMarkdown' => ['nullable', 'string', 'max:100000'], 'minimumWatchPercentage' => ['required', 'integer', 'min:1', 'max:100'],
            'passingScore' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $column = match ($property) {
            'contentMarkdown' => 'content_markdown', 'minimumWatchPercentage' => 'minimum_watch_percentage',
            'passingScore' => 'passing_score', default => $property,
        };
        $this->version->update([$column => $this->{$property}]);
    }

    public function requestUpload(int $lessonIndex, RequestVideoUpload $action, PlatformAccess $access): string
    {
        $access->authorize(); abort_unless($lessonIndex === 0, 404);
        $upload = $action->handle($this->version); $this->version->load('video');
        return $upload->uploadUrl;
    }

    public function uploadCompleted(int $lessonIndex, PlatformAccess $access): void
    {
        $access->authorize(); abort_unless($lessonIndex === 0, 404);
        $this->version->video?->update(['status' => VideoStatus::Processing]); $this->version->load('video');
    }

    public function publish(PublishModuleVersion $action, PlatformAccess $access): void
    {
        $action->handle($this->version, $access->authorize(), $this->restartInProgress);
        session()->flash('status', __('Shared module published.'));
        $this->redirectRoute('platform.shared-modules.show', ['module' => $this->module], navigate: true);
    }

    public function with(ModulePropagationImpact $impact, ModuleVersionValidator $validator): array
    {
        $this->version->load('video');
        return ['impact' => $impact->summarize($this->version), 'problems' => $validator->problems($this->version)];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero :kicker="__('Shared Module draft')" :title="$title" :description="__('Edit the module content and replace its video before publishing the new immutable version.')">
        <flux:button :href="route('platform.shared-modules.show', ['module' => $module])" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
    </x-page-hero>
    <x-status-message />

    <section class="detail-card space-y-5">
        <div class="grid gap-4 lg:grid-cols-2"><flux:input wire:model.blur="title" class="admin-control" :label="__('Module title')" /><flux:textarea wire:model.blur="description" class="admin-control" :label="__('Description')" rows="2" /></div>
        <div class="grid gap-4 sm:grid-cols-2"><flux:input type="number" min="1" max="100" wire:model.blur="minimumWatchPercentage" class="admin-control" :label="__('Watch threshold (%)')" /><flux:input type="number" min="1" max="100" wire:model.blur="passingScore" class="admin-control" :label="__('Passing score (%)')" /></div>
    </section>

    <section class="detail-card">
        <div class="flex flex-wrap items-center justify-between gap-4" x-data="lessonVideoUpload(0, {{ Js::from(['fileTooLarge' => __('This video is larger than 200 MB. Select a smaller file.')]) }})">
            <div><h2 class="detail-card-title">{{ __('Video') }}</h2><p class="mt-1 text-sm text-[#707a80]">{{ $version->video ? $version->video->status->label().' · '.$version->video->formattedDuration() : __('No video attached') }}</p><template x-if="uploading"><p class="mt-2 text-sm font-semibold text-[#1c6b84]" x-text="`${progress}%`"></p></template><p class="mt-2 text-sm text-[#b23a3a]" x-show="error" x-text="error"></p></div>
            <div><input type="file" accept="video/*" class="hidden" x-ref="file" @change="start($event)"><flux:button variant="primary" x-on:click="$refs.file.click()" ::disabled="uploading" icon="arrow-up-tray">{{ $version->video ? __('Replace video') : __('Upload video') }}</flux:button></div>
        </div>
    </section>

    @if ($problems)<flux:callout variant="danger" :heading="__('This version is not ready to publish.')"><ul class="mt-2 list-disc pl-5">@foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul></flux:callout>@endif
    <section class="detail-card space-y-5">
        <div><h2 class="detail-card-title">{{ __('Publication impact') }}</h2><p class="mt-1 text-sm text-[#707a80]">{{ __('Publishing updates future training while preserving completed history.') }}</p></div>
        <div class="grid gap-4 sm:grid-cols-3"><div class="metric-card metric-card--slate"><p class="metric-label">{{ __('Affected courses') }}</p><p class="metric-value">{{ $impact['affected_courses'] }}</p></div><div class="metric-card metric-card--teal"><p class="metric-label">{{ __('Not started') }}</p><p class="metric-value">{{ $impact['not_started_assignments'] }}</p></div><div class="metric-card metric-card--amber"><p class="metric-label">{{ __('In progress') }}</p><p class="metric-value">{{ $impact['in_progress_assignments'] }}</p></div></div>
        <flux:checkbox wire:model="restartInProgress" :label="__('Restart in-progress assignments')" :description="__('When selected, existing progress is not transferred to the new assignment.')" />
        <flux:button wire:click="publish" wire:loading.attr="disabled" variant="primary" :disabled="count($problems) > 0">{{ __('Publish Shared Module') }}</flux:button>
    </section>
</div>
