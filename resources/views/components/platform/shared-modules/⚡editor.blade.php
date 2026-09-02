<?php

use App\Actions\Modules\PublishModuleVersion;
use App\Actions\Modules\SaveSharedModuleEditorDraft;
use App\Actions\Videos\LinkExistingVideo;
use App\Actions\Videos\RequestVideoUpload;
use App\Enums\ModuleVersionStatus;
use App\Enums\QuestionType;
use App\Enums\VideoStatus;
use App\Exceptions\VideoProviderException;
use App\Models\ContentImage;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Services\Courses\LessonContentRenderer;
use App\Services\Modules\ModulePropagationImpact;
use App\Services\Modules\ModuleVersionValidator;
use App\Services\Modules\SharedModuleDraftWriter;
use App\Services\Platform\PlatformAccess;
use App\Services\Video\VideoLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    public array $questions = [];

    public string $assessmentRevision = '';

    public bool $assessmentDirty = false;

    public string $assessmentStatus = '';

    public ?string $assessmentError = null;

    public ?string $saveError = null;

    public bool $uploadInProgress = false;

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
        $this->loadAssessment();
    }

    public function updated(string $property): void
    {
        // Persistible editor fields are intentionally deferred to the global save action.
    }

    public function requestUpload(int $lessonIndex, RequestVideoUpload $action, PlatformAccess $access): string
    {
        $actor = $access->authorize();
        abort_unless($lessonIndex === 0, 404);
        $upload = $action->handle($this->version, platformActor: $actor);
        $this->uploadInProgress = true;
        $this->version->load('video');

        return $upload->uploadUrl;
    }

    public function uploadCompleted(int $lessonIndex, PlatformAccess $access, VideoLibrary $library): void
    {
        $access->authorize();
        abort_unless($lessonIndex === 0, 404);
        $this->version->video?->update(['status' => VideoStatus::Processing]);
        $this->uploadInProgress = false;
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
        if ($this->assessmentDirty || $this->uploadInProgress) {
            $this->addError('publish', __('Save all assessment changes before publishing.'));

            return;
        }
        $action->handle($this->version, $access->authorize(), $this->restartInProgress);
        session()->flash('status', __('Shared module published.'));
        $this->redirectRoute('platform.shared-modules.show', ['module' => $this->module], navigate: true);
    }

    public function markAssessmentDirty(): void
    {
        $this->assessmentDirty = true;
        $this->assessmentStatus = 'unsaved';
        $this->assessmentError = null;
    }

    public function saveDraft(bool $close, SaveSharedModuleEditorDraft $action, PlatformAccess $access): void
    {
        if ($close && $this->uploadInProgress) {
            $this->saveError = __('Wait for active uploads to finish before closing.');

            return;
        }
        if (! $this->assessmentDirty) {
            if ($close) {
                $this->redirectRoute('platform.shared-modules.show', ['module' => $this->module], navigate: true);
            }

            return;
        }

        $this->assessmentStatus = 'saving';
        $this->assessmentError = null;
        $this->saveError = null;
        $actor = $access->authorize();

        try {
            $this->assessmentRevision = $action->handle(
                $this->version,
                $actor,
                [
                    'id' => $this->version->id, 'title' => $this->title, 'description' => $this->description,
                    'content_markdown' => $this->contentMarkdown, 'minimum_watch_percentage' => $this->minimumWatchPercentage,
                    'passing_score' => $this->passingScore, 'questions' => $this->questions,
                ],
                $this->assessmentRevision,
            );
        } catch (ValidationException $exception) {
            $this->assessmentStatus = 'error';
            $this->assessmentError = collect($exception->errors())->flatten()->first() ?? __('The assessment could not be saved.');
            $this->saveError = $this->assessmentError;
            $this->dispatch('editor-save-finished');

            return;
        } catch (LogicException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->assessmentStatus = 'error';
            $this->assessmentError = __('The assessment could not be saved. Try again.');
            $this->saveError = $this->assessmentError;
            $this->dispatch('editor-save-finished');

            return;
        }

        $this->assessmentDirty = false;
        $this->assessmentStatus = 'saved';
        $this->dispatch('assessment-saved', moduleId: $this->version->id);
        $this->dispatch('editor-saved');
        $this->dispatch('editor-save-finished');
        if ($close) {
            $this->redirectRoute('platform.shared-modules.show', ['module' => $this->module], navigate: true);
        }
    }

    public function addQuestion(): void
    {
        if ($this->assessmentDirty) {
            $this->assessmentError = __('Save the assessment before adding questions or answers.');

            return;
        }

        DB::transaction(function (): void {
            $question = Question::query()->create(['company_id' => null, 'lesson_id' => $this->version->id, 'type' => QuestionType::SingleChoice, 'prompt' => 'New question', 'position' => $this->version->questions()->count() + 1, 'max_attempts' => 3]);
            foreach ([1, 2] as $position) {
                QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => "Option {$position}", 'is_correct' => $position === 1, 'position' => $position]);
            }
        });
        $this->loadAssessment();
    }

    public function addOption(int $questionIndex): void
    {
        if ($this->assessmentDirty) {
            $this->assessmentError = __('Save the assessment before adding questions or answers.');

            return;
        }

        abort_unless(isset($this->questions[$questionIndex]), 404);
        $question = $this->version->questions()->whereKey($this->questions[$questionIndex]['id'])->firstOrFail();
        QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'New option', 'is_correct' => false, 'position' => $question->options()->count() + 1]);
        $this->loadAssessment();
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

    private function loadAssessment(): void
    {
        $this->questions = $this->version->questions()->with('options')->get()->map(fn (Question $question): array => [
            'id' => $question->id,
            'prompt' => $question->prompt,
            'max_attempts' => $question->max_attempts,
            'options' => $question->options->map(fn (QuestionOption $option): array => [
                'id' => $option->id,
                'text' => $option->text,
                'is_correct' => $option->is_correct,
            ])->all(),
        ])->all();
        $this->assessmentRevision = app(SharedModuleDraftWriter::class)->revision($this->version);
        $this->assessmentDirty = false;
        $this->assessmentError = null;
    }
};
?>

<div class="admin-page space-y-7" style="padding-bottom: calc(var(--editor-save-bar-height, 8rem) + 1rem);" x-data="{ pageDirty: false, saving: false, beforeUnloadHandler: null, saveBarObserver: null, markEditorDirty() { if (! this.pageDirty) { this.pageDirty = true; $wire.set('assessmentDirty', true, false); } }, hasOpenDialog() { return Boolean(document.querySelector('dialog[open], [role=dialog][aria-modal=true]:not([hidden])')); }, observeSaveBar(element) { this.saveBarObserver?.disconnect(); this.saveBarObserver = new ResizeObserver(entries => this.$root.style.setProperty('--editor-save-bar-height', `${entries[0].contentRect.height}px`)); this.saveBarObserver.observe(element); }, init() { this.beforeUnloadHandler = event => { if (this.pageDirty) { event.preventDefault(); event.returnValue = ''; } }; window.addEventListener('beforeunload', this.beforeUnloadHandler); this.keyHandler = event => { if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') { event.preventDefault(); if (! this.pageDirty || this.saving || this.hasOpenDialog()) return; this.saving = true; $wire.saveDraft(false); } }; window.addEventListener('keydown', this.keyHandler); }, destroy() { window.removeEventListener('beforeunload', this.beforeUnloadHandler); window.removeEventListener('keydown', this.keyHandler); this.saveBarObserver?.disconnect(); } }" x-on:assessment-dirty="pageDirty = true" x-on:assessment-saved.window="pageDirty = false; saving = false" x-on:editor-saved.window="pageDirty = false; saving = false" x-on:editor-save-finished.window="saving = false" x-on:livewire:navigate.window="if (pageDirty && ! window.confirm({{ Js::from(__('You have unsaved changes. Leave without saving?')) }})) $event.preventDefault()" x-on:oceanix-open-image-library.window="$wire.openImageLibrary()" x-on:oceanix-open-video-library.window="$wire.openEditorVideoLibrary($event.detail.model)">
    <x-page-hero :kicker="__('Shared Module draft')" :title="$title" :description="__('Edit the module content and replace its video before publishing the new immutable version.')">
        <flux:button :href="route('platform.shared-modules.show', ['module' => $module])" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
    </x-page-hero>
    <x-status-message />

    <section class="detail-card space-y-5">
        <div class="grid gap-4 lg:grid-cols-2"><flux:input wire:model.defer="title" x-on:input="markEditorDirty" class="admin-control" :label="__('Module title')" /><flux:textarea wire:model.defer="description" x-on:input="markEditorDirty" class="admin-control" :label="__('Description')" rows="2" /></div>
        <div class="grid gap-4 sm:grid-cols-2"><flux:input type="number" min="1" max="100" wire:model.defer="minimumWatchPercentage" x-on:input="markEditorDirty" class="admin-control" :label="__('Watch threshold (%)')" /><flux:input type="number" min="1" max="100" wire:model.defer="passingScore" x-on:input="markEditorDirty" class="admin-control" :label="__('Passing score (%)')" /></div>
        <flux:editor
            wire:model.defer="contentMarkdown"
            x-on:input="markEditorDirty"
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

    <section class="detail-card space-y-4" x-data="{ dirty: {{ Js::from($assessmentDirty) }}, markDirty() { if (! this.dirty) { this.dirty = true; $dispatch('assessment-dirty'); $wire.set('assessmentDirty', true, false); } } }" x-on:assessment-saved.window="dirty = false">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="detail-card-title">{{ __('Assessment') }}</h2><p class="mt-1 text-sm text-[#707a80]">{{ __('Assessment changes are saved explicitly.') }}</p></div>
        </div>
        @if ($assessmentError)<flux:callout variant="danger" :heading="$assessmentError" />@endif
        <div class="space-y-3">
            @if ($questions === [])<x-empty-state icon="question-mark-circle" :title="__('No assessment questions yet')" :description="__('Add a question to build this assessment.')" />@endif
            @foreach ($questions as $questionIndex => $question)
                <div class="rounded-[18px] border border-[#e4e9ec] p-4" wire:key="standalone-question-{{ $question['id'] }}">
                    <p class="mb-3 text-sm font-bold text-[#4f5960]">{{ __('Question :number', ['number' => $questionIndex + 1]) }}</p>
                    <div class="grid gap-3 sm:grid-cols-[1fr_140px]"><flux:input wire:model.defer="questions.{{ $questionIndex }}.prompt" x-on:input="markDirty" :label="__('Question')" /><flux:input type="number" wire:model.defer="questions.{{ $questionIndex }}.max_attempts" x-on:input="markDirty" :label="__('Attempts')" /></div>
                    <fieldset class="mt-3 space-y-2"><legend class="mb-2 text-sm font-semibold">{{ __('Correct answer') }}</legend>
                        @foreach ($question['options'] as $optionIndex => $option)
                            <div class="flex items-center gap-2" wire:key="standalone-option-{{ $option['id'] }}"><input type="radio" x-on:change="@foreach ($question['options'] as $candidateIndex => $candidate) $wire.set('questions.{{ $questionIndex }}.options.{{ $candidateIndex }}.is_correct', {{ $candidateIndex === $optionIndex ? 'true' : 'false' }}, false); @endforeach markDirty()" @checked($option['is_correct']) name="standalone-correct-{{ $question['id'] }}" aria-label="{{ __('Mark answer :number as correct', ['number' => $optionIndex + 1]) }}"><flux:input wire:model.defer="questions.{{ $questionIndex }}.options.{{ $optionIndex }}.text" x-on:input="markDirty" :label="__('Answer :number', ['number' => $optionIndex + 1])" class="flex-1" /></div>
                        @endforeach
                    </fieldset>
                    <flux:button wire:click="addOption({{ $questionIndex }})" x-bind:disabled="dirty" variant="ghost" size="sm" class="mt-3">{{ __('Add option') }}</flux:button>
                </div>
            @endforeach
        </div>
        <div class="flex justify-end"><flux:button wire:click="addQuestion" x-bind:disabled="dirty" variant="ghost" icon="plus" class="w-full sm:w-auto">{{ __('Add question') }}</flux:button></div>
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
        @error('publish')<flux:callout variant="danger" :heading="$message" />@enderror
        <flux:button wire:click="publish" wire:loading.attr="disabled" variant="primary" :disabled="count($problems) > 0 || $assessmentDirty">{{ __('Publish Shared Module') }}</flux:button>
    </section>

    <x-editor-save-bar :dirty="$assessmentDirty" :dirty-module-count="$assessmentDirty ? 1 : 0" :error="$saveError" :upload-in-progress="$uploadInProgress" />
</div>
