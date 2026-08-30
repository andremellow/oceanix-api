<?php

use App\Actions\Modules\PublishModuleVersion;
use App\Actions\Videos\LinkExistingVideo;
use App\Actions\Videos\RequestVideoUpload;
use App\Enums\ModuleVersionStatus;
use App\Enums\VideoStatus;
use App\Exceptions\VideoProviderException;
use App\Models\ContentImage;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Services\Courses\LessonContentRenderer;
use App\Services\Courses\LessonContentSanitizer;
use App\Services\Modules\ModulePropagationImpact;
use App\Services\Modules\ModuleVersionValidator;
use App\Services\Platform\PlatformAccess;
use App\Services\Video\VideoLibrary;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::platform')] class extends Component
{
    use WithFileUploads;

    public Module $module;

    public ModuleVersion $version;

    public string $title = '';

    public string $description = '';

    public string $contentMarkdown = '';

    public int $minimumWatchPercentage = 90;

    public int $passingScore = 70;

    public bool $restartInProgress = false;

    public bool $imageLibraryOpen = false;

    public $contentImageUpload;

    public bool $videoLibraryOpen = false;

    public array $videoLibraryItems = [];

    public string $videoLibrarySearch = '';

    public ?string $videoLibraryError = null;

    public function mount(Module $module, PlatformAccess $access): void
    {
        $actor = $access->authorize();
        abort_unless($module->is_shared && $module->company_id === null, 404);
        $this->module = $module;
        $this->version = $module->versions()->where('status', ModuleVersionStatus::Draft->value)->firstOrFail();
        $this->title = $this->version->title;
        $this->description = (string) $this->version->description;
        $this->contentMarkdown = app(LessonContentRenderer::class)
            ->editorContent((string) $this->version->content_markdown);
        $this->minimumWatchPercentage = $this->version->minimum_watch_percentage;
        $this->passingScore = $this->version->passing_score;
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['title', 'description', 'contentMarkdown', 'minimumWatchPercentage', 'passingScore'], true)) {
            return;
        }
        $this->validate([
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'],
            'contentMarkdown' => ['nullable', 'string', 'max:100000'], 'minimumWatchPercentage' => ['required', 'integer', 'min:1', 'max:100'],
            'passingScore' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $column = match ($property) {
            'contentMarkdown' => 'content_markdown', 'minimumWatchPercentage' => 'minimum_watch_percentage',
            'passingScore' => 'passing_score', default => $property,
        };
        $value = $property === 'contentMarkdown'
            ? app(LessonContentSanitizer::class)->sanitize($this->contentMarkdown)
            : $this->{$property};
        if ($property === 'contentMarkdown') {
            $this->contentMarkdown = $value;
        }
        $this->version->update([$column => $value]);
    }

    public function requestUpload(int $lessonIndex, RequestVideoUpload $action, PlatformAccess $access): string
    {
        $actor = $access->authorize();
        abort_unless($lessonIndex === 0, 404);
        $upload = $action->handle($this->version, platformActor: $actor);
        $this->version->load('video');

        return $upload->uploadUrl;
    }

    public function uploadCompleted(int $lessonIndex, PlatformAccess $access, VideoLibrary $library): void
    {
        $access->authorize();
        abort_unless($lessonIndex === 0, 404);
        $this->version->video?->update(['status' => VideoStatus::Processing]);
        $this->version->load('video');

        if ($this->videoLibraryOpen) {
            $this->videoLibrarySearch = '';
            $this->loadVideoLibrary($library);
        }
    }

    public function openEditorVideoLibrary(string $model, VideoLibrary $library, PlatformAccess $access): void
    {
        $access->authorize();
        abort_unless($model === 'contentMarkdown', 422);
        $this->videoLibrarySearch = '';
        $this->videoLibraryError = null;
        $this->videoLibraryOpen = true;
        $this->loadVideoLibrary($library);
    }

    public function searchVideoLibrary(VideoLibrary $library, PlatformAccess $access): void
    {
        $access->authorize();
        abort_unless($this->videoLibraryOpen, 404);
        $this->loadVideoLibrary($library);
    }

    public function selectLibraryVideo(string $assetId, LinkExistingVideo $action, PlatformAccess $access): void
    {
        $actor = $access->authorize();
        abort_unless(collect($this->videoLibraryItems)->contains(fn (array $item): bool => hash_equals((string) $item['asset_id'], $assetId) && $item['status'] === VideoStatus::Ready->value), 404);
        $item = collect($this->videoLibraryItems)->first(fn (array $item): bool => hash_equals((string) $item['asset_id'], $assetId));
        $action->handle($this->version, $assetId, allowAnyOwner: true, platformActor: $actor);
        $this->version->load('video');
        $this->dispatch('oceanix:insert-video', model: 'contentMarkdown', previewUrl: $item['preview_url'], posterUrl: $item['thumbnail_url'], title: $item['title'], aspectRatio: $item['aspect_ratio']);
        $this->videoLibraryOpen = false;
    }

    public function openImageLibrary(PlatformAccess $access): void
    {
        $access->authorize();
        $this->imageLibraryOpen = true;
        $this->resetValidation('contentImageUpload');
    }

    public function uploadContentImage(PlatformAccess $access): void
    {
        $access->authorize();
        $this->validate(['contentImageUpload' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240']]);
        $upload = $this->contentImageUpload;
        $disk = (string) config('filesystems.content_images_disk', 'public');
        $path = $upload->store((string) config('filesystems.content_images_path', 'content-images'), $disk);
        abort_if($path === false, 500);
        $image = ContentImage::query()->create([
            'company_id' => null, 'is_shared' => true, 'name' => $upload->getClientOriginalName(), 'disk' => $disk,
            'path' => $path, 'mime_type' => $upload->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $upload->getSize(),
        ]);
        $this->reset('contentImageUpload');
        $this->selectContentImage($image->id, $access);
    }

    public function selectContentImage(int $imageId, PlatformAccess $access): void
    {
        $access->authorize();
        $image = ContentImage::query()->where('is_shared', true)->findOrFail($imageId);
        $this->dispatch('oceanix:insert-image', model: 'contentMarkdown', url: $image->url(), alt: pathinfo($image->name, PATHINFO_FILENAME));
        $this->imageLibraryOpen = false;
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

        return [
            'impact' => $impact->summarize($this->version),
            'problems' => $validator->problems($this->version),
            'contentImages' => ContentImage::query()->where('is_shared', true)->latest()->limit(60)->get(),
            'editorVideoPreview' => rescue(fn (): ?array => $this->version->video === null ? null : app(VideoLibrary::class)->preview($this->version->video), null, report: false),
        ];
    }

    private function loadVideoLibrary(VideoLibrary $library): void
    {
        try {
            $this->videoLibraryItems = $library->items($this->videoLibrarySearch, allOwners: true);
        } catch (VideoProviderException) {
            $this->videoLibraryItems = [];
            $this->videoLibraryError = __('The video library could not be loaded. Try again.');
        }
    }
};
?>

<div class="admin-page space-y-7" x-on:oceanix-open-image-library.window="$wire.openImageLibrary()" x-on:oceanix-open-video-library.window="$wire.openEditorVideoLibrary($event.detail.model)">
    <x-page-hero :kicker="__('Shared Module draft')" :title="$title" :description="__('Edit the module content and replace its video before publishing the new immutable version.')">
        <flux:button :href="route('platform.shared-modules.show', ['module' => $module])" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
    </x-page-hero>
    <x-status-message />

    <section class="detail-card space-y-5">
        <div class="grid gap-4 lg:grid-cols-2"><flux:input wire:model.blur="title" class="admin-control" :label="__('Module title')" /><flux:textarea wire:model.blur="description" class="admin-control" :label="__('Description')" rows="2" /></div>
        <div class="grid gap-4 sm:grid-cols-2"><flux:input type="number" min="1" max="100" wire:model.blur="minimumWatchPercentage" class="admin-control" :label="__('Watch threshold (%)')" /><flux:input type="number" min="1" max="100" wire:model.blur="passingScore" class="admin-control" :label="__('Passing score (%)')" /></div>
        <flux:editor
            wire:model.live.debounce.500ms="contentMarkdown"
            data-oceanix-editor-model="contentMarkdown"
            data-oceanix-video-preview-url="{{ data_get($editorVideoPreview, 'preview_url') }}"
            data-oceanix-video-poster-url="{{ data_get($editorVideoPreview, 'poster_url') }}"
            data-oceanix-video-title="{{ $title }}"
            data-oceanix-video-aspect-ratio="{{ data_get($editorVideoPreview, 'aspect_ratio', '16/9') }}"
            class="oceanix-content-editor"
            :label="__('Module content')"
            toolbar="heading | bold italic underline strike | bullet ordered blockquote link | align | image image-left image-center image-right image-size video ~ fullscreen undo redo" />
    </section>

    <section class="detail-card">
        <div class="flex flex-wrap items-center justify-between gap-4" x-data="lessonVideoUpload(0, {{ Js::from(['fileTooLarge' => __('This video is larger than 200 MB. Select a smaller file.')]) }})">
            <div><h2 class="detail-card-title">{{ __('Video') }}</h2><p class="mt-1 text-sm text-[#707a80]">{{ $version->video ? $version->video->status->label().' · '.$version->video->formattedDuration() : __('No video attached') }}</p><template x-if="uploading"><p class="mt-2 text-sm font-semibold text-[#1c6b84]" x-text="`${progress}%`"></p></template><p class="mt-2 text-sm text-[#b23a3a]" x-show="error" x-text="error"></p></div>
            <div><input type="file" accept="video/*" class="hidden" x-ref="file" @change="start($event)"><flux:button variant="primary" x-on:click="$refs.file.click()" ::disabled="uploading" icon="arrow-up-tray">{{ $version->video ? __('Replace video') : __('Upload video') }}</flux:button></div>
        </div>
    </section>

    <flux:modal wire:model.self="imageLibraryOpen" class="max-w-4xl">
        <div class="space-y-6">
            <div><flux:heading size="lg">{{ __('Image library') }}</flux:heading><flux:text class="mt-2">{{ __('Upload an image or reuse one from the shared gallery.') }}</flux:text></div>
            <form wire:submit="uploadContentImage" class="rounded-[18px] border border-dashed border-[#cfd8dd] bg-[#f7f9fa] p-5">
                <input wire:model="contentImageUpload" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="block w-full rounded-xl border border-[#cfd8dd] bg-white p-3 text-sm">
                @error('contentImageUpload') <p class="mt-2 text-sm font-medium text-red-600">{{ $message }}</p> @enderror
                <flux:button type="submit" wire:loading.attr="disabled" variant="primary" class="mt-4">{{ __('Upload and insert image') }}</flux:button>
            </form>
            <div class="grid max-h-[45vh] grid-cols-2 gap-3 overflow-y-auto sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($contentImages as $image)
                    <button type="button" wire:click="selectContentImage({{ $image->id }})" class="overflow-hidden rounded-2xl border border-[#dde3e7] bg-white text-left" wire:key="module-content-image-{{ $image->id }}"><img src="{{ $image->url() }}" alt="" class="aspect-[4/3] w-full object-cover"><span class="block truncate px-3 py-2 text-xs font-semibold">{{ $image->name }}</span></button>
                @endforeach
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="videoLibraryOpen" class="max-w-4xl">
        <div class="space-y-5">
            <div><flux:heading size="lg">{{ __('Video library') }}</flux:heading><flux:text class="mt-2">{{ __('Select a ready video from the platform Cloudflare library.') }}</flux:text></div>
            <div class="rounded-[18px] border border-dashed border-[#cfd8dd] bg-[#f7f9fa] p-4" x-data="lessonVideoUpload(0, {{ Js::from(['fileTooLarge' => __('This video is larger than 200 MB. Select a smaller file.')]) }})">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div><p class="text-sm font-bold">{{ __('Upload a new video') }}</p><p class="mt-1 text-xs text-[#707a80]">{{ __('The file is uploaded directly and will appear in this library while it is processing.') }}</p></div>
                    <div><input type="file" accept="video/*" class="hidden" x-ref="file" x-on:change="start($event)"><flux:button variant="primary" icon="arrow-up-tray" x-on:click="$refs.file.click()" ::disabled="uploading"><span x-show="! uploading">{{ __('Choose video') }}</span><span x-show="uploading" x-text="`${progress}%`"></span></flux:button></div>
                </div>
                <p class="mt-3 text-sm font-medium text-red-600" x-show="error" x-text="error"></p>
            </div>
            <div class="flex gap-2"><flux:input wire:model="videoLibrarySearch" wire:keydown.enter="searchVideoLibrary" class="flex-1" :label="__('Search videos')" /><flux:button wire:click="searchVideoLibrary" class="self-end" variant="ghost">{{ __('Search') }}</flux:button></div>
            @if ($videoLibraryError)
                <flux:callout variant="danger" :heading="$videoLibraryError" />
            @elseif ($videoLibraryItems === [])
                <x-empty-state icon="film" :title="__('No videos found')" :description="__('Upload a video and it will appear here.')" />
            @else
                <div class="grid max-h-[55vh] gap-3 overflow-y-auto sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($videoLibraryItems as $item)
                        <article class="overflow-hidden rounded-2xl border border-[#dde3e7] bg-white" wire:key="module-video-{{ $item['asset_id'] }}">
                            <div class="aspect-video bg-[#e8eef1]">@if ($item['thumbnail_url'])<img src="{{ $item['thumbnail_url'] }}" alt="" class="size-full object-cover">@else<span class="grid size-full place-items-center"><flux:icon.film class="size-8 text-[#8a9298]" /></span>@endif</div>
                            <div class="space-y-2 p-3"><p class="truncate text-sm font-bold">{{ $item['title'] }}</p><p class="text-xs text-[#7d878e]">{{ $item['duration'] }} · {{ $item['status_label'] }}</p><flux:button wire:click="selectLibraryVideo('{{ $item['asset_id'] }}')" variant="primary" size="sm" class="w-full" :disabled="$item['status'] !== App\Enums\VideoStatus::Ready->value">{{ __('Use video') }}</flux:button></div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </flux:modal>

    @if ($problems)<flux:callout variant="danger" :heading="__('This version is not ready to publish.')"><ul class="mt-2 list-disc pl-5">@foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul></flux:callout>@endif
    <section class="detail-card space-y-5">
        <div><h2 class="detail-card-title">{{ __('Publication impact') }}</h2><p class="mt-1 text-sm text-[#707a80]">{{ __('Publishing updates future training while preserving completed history.') }}</p></div>
        <div class="grid gap-4 sm:grid-cols-3"><div class="metric-card metric-card--slate"><p class="metric-label">{{ __('Affected courses') }}</p><p class="metric-value">{{ $impact['affected_courses'] }}</p></div><div class="metric-card metric-card--teal"><p class="metric-label">{{ __('Not started') }}</p><p class="metric-value">{{ $impact['not_started_assignments'] }}</p></div><div class="metric-card metric-card--amber"><p class="metric-label">{{ __('In progress') }}</p><p class="metric-value">{{ $impact['in_progress_assignments'] }}</p></div></div>
        <flux:checkbox wire:model="restartInProgress" :label="__('Restart in-progress assignments')" :description="__('When selected, existing progress is not transferred to the new assignment.')" />
        <flux:button wire:click="publish" wire:loading.attr="disabled" variant="primary" :disabled="count($problems) > 0">{{ __('Publish Shared Module') }}</flux:button>
    </section>
</div>
