<?php

use App\Actions\Courses\PublishSharedCourseDraft;
use App\Actions\Modules\CreateModuleDraft;
use App\Actions\Videos\LinkExistingVideo;
use App\Actions\Videos\RequestVideoUpload;
use App\Enums\CourseVersionStatus;
use App\Enums\ModuleVersionStatus;
use App\Enums\QuestionType;
use App\Enums\VideoStatus;
use App\Exceptions\CoursePublicationException;
use App\Exceptions\VideoProviderException;
use App\Models\ContentImage;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Services\Courses\LessonContentRenderer;
use App\Services\Courses\LessonContentSanitizer;
use App\Services\Platform\PlatformAccess;
use App\Services\SharedContent\SharedContentCatalog;
use App\Services\Video\VideoLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::platform')] class extends Component
{
    use WithFileUploads;

    public Course $course;

    public CourseVersion $version;

    public array $courseForm = [];

    public array $versionForm = [];

    public array $modules = [];

    public array $expanded = [];

    public string $moduleSearch = '';

    public ?int $selectedModuleId = null;

    public bool $confirmingPublish = false;

    public bool $restartInProgress = false;

    public bool $imageLibraryOpen = false;

    public string $imageEditorModel = '';

    public $contentImageUpload;

    public bool $videoLibraryOpen = false;

    public string $videoEditorModel = '';

    public array $videoLibraryItems = [];

    public string $videoLibrarySearch = '';

    public ?string $videoLibraryError = null;

    public function mount(Course $course, PlatformAccess $access, CreateModuleDraft $createModuleDraft): void
    {
        $actor = $access->authorize();
        abort_unless($course->is_shared && $course->company_id === null, 404);
        $this->course = $course;
        $this->version = $course->versions()->where('status', CourseVersionStatus::Draft->value)->firstOrFail();
        $this->prepareModuleDrafts($createModuleDraft, $actor);
        $this->courseForm = ['code' => $course->code, 'title' => $course->title, 'description' => $course->description];
        $this->versionForm = ['description' => $this->version->description];
        $this->loadModules();
        $this->expanded = collect($this->modules)->pluck('id')->all();
    }

    public function updated(string $property, mixed $value): void
    {
        if (str_starts_with($property, 'courseForm.')) {
            $field = substr($property, 11);
            abort_unless(in_array($field, ['code', 'title', 'description'], true), 404);
            $this->validate(['courseForm.code' => ['required', 'string', 'max:40', 'unique:courses,code,'.$this->course->id], 'courseForm.title' => ['required', 'string', 'max:200'], 'courseForm.description' => ['nullable', 'string', 'max:2000']]);
            $this->course->update([$field => $value]);
            if ($field === 'title') {
                $this->version->update(['title' => $value]);
            }

            return;
        }

        if ($property === 'versionForm.description') {
            $this->validate(['versionForm.description' => ['nullable', 'string', 'max:2000']]);
            $this->version->update(['description' => $value]);

            return;
        }

        if (preg_match('/^modules\.(\d+)\.([a-z_]+)$/', $property, $matches)) {
            $index = (int) $matches[1];
            $field = $matches[2];
            abort_unless(in_array($field, ['title', 'description', 'content_markdown', 'minimum_watch_percentage', 'passing_score'], true), 404);
            $this->validate([
                "modules.{$index}.title" => ['required', 'string', 'max:200'],
                "modules.{$index}.description" => ['nullable', 'string', 'max:2000'],
                "modules.{$index}.content_markdown" => ['nullable', 'string', 'max:100000'],
                "modules.{$index}.minimum_watch_percentage" => ['required', 'integer', 'min:1', 'max:100'],
                "modules.{$index}.passing_score" => ['required', 'integer', 'min:1', 'max:100'],
            ]);
            if ($field === 'content_markdown') {
                $value = app(LessonContentSanitizer::class)->sanitize((string) $value);
                $this->modules[$index][$field] = $value;
            }
            $module = $this->moduleAt($index);
            $module->update([$field => $value]);

            return;
        }

        if (preg_match('/^modules\.(\d+)\.questions\.(\d+)\.(prompt|max_attempts)$/', $property, $matches)) {
            $moduleIndex = (int) $matches[1];
            $questionIndex = (int) $matches[2];
            $this->validate([
                "modules.{$moduleIndex}.questions.{$questionIndex}.prompt" => ['required', 'string', 'max:1000'],
                "modules.{$moduleIndex}.questions.{$questionIndex}.max_attempts" => ['required', 'integer', 'min:1', 'max:10'],
            ]);
            $question = $this->questionAt((int) $matches[1], (int) $matches[2]);
            $question->update([$matches[3] => $value]);

            return;
        }

        if (preg_match('/^modules\.(\d+)\.questions\.(\d+)\.options\.(\d+)\.(text|is_correct)$/', $property, $matches)) {
            $moduleIndex = (int) $matches[1];
            $questionIndex = (int) $matches[2];
            $optionIndex = (int) $matches[3];
            $this->validate([
                "modules.{$moduleIndex}.questions.{$questionIndex}.options.{$optionIndex}.text" => ['required', 'string', 'max:1000'],
                "modules.{$moduleIndex}.questions.{$questionIndex}.options.{$optionIndex}.is_correct" => ['boolean'],
            ]);
            $option = $this->optionAt((int) $matches[1], (int) $matches[2], (int) $matches[3]);
            $option->update([$matches[4] => $value]);
        }
    }

    public function toggleModule(int $id): void
    {
        $this->expanded = in_array($id, $this->expanded, true) ? array_values(array_diff($this->expanded, [$id])) : [...$this->expanded, $id];
    }

    public function addModule(int $moduleVersionId, SharedContentCatalog $catalog, CreateModuleDraft $createModuleDraft, PlatformAccess $access): void
    {
        $source = $catalog->availableModules()->firstWhere('id', $moduleVersionId);
        abort_unless($source instanceof ModuleVersion, 404);
        abort_if($this->version->moduleCompositions()->where('lesson_id', $moduleVersionId)->exists(), 422);
        CourseVersionModule::query()->create(['course_version_id' => $this->version->id, 'module_version_id' => $source->id, 'position' => $this->version->moduleCompositions()->count() + 1, 'is_required' => true]);
        $this->prepareModuleDrafts($createModuleDraft, $access->authorize());
        $this->loadModules();
        $this->expanded = collect($this->modules)->pluck('id')->all();
    }

    public function addSelectedModule(SharedContentCatalog $catalog, CreateModuleDraft $createModuleDraft, PlatformAccess $access): void
    {
        abort_if($this->selectedModuleId === null, 422);
        $this->addModule($this->selectedModuleId, $catalog, $createModuleDraft, $access);
        $this->selectedModuleId = null;
    }

    public function removeModule(int $moduleIndex): void
    {
        abort_unless(isset($this->modules[$moduleIndex]), 404);
        $this->version->moduleCompositions()->whereKey($this->modules[$moduleIndex]['composition_id'])->delete();
        foreach ($this->version->moduleCompositions()->get()->values() as $index => $composition) {
            $composition->update(['position' => $index + 1]);
        }
        $this->loadModules();
    }

    public function addQuestion(int $moduleIndex): void
    {
        $module = $this->moduleAt($moduleIndex);
        DB::transaction(function () use ($module): void {
            $question = Question::query()->create(['company_id' => null, 'lesson_id' => $module->id, 'type' => QuestionType::SingleChoice, 'prompt' => __('New question'), 'position' => $module->questions()->count() + 1, 'max_attempts' => 3]);
            foreach ([1, 2] as $position) {
                QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => __('Option :number', ['number' => $position]), 'is_correct' => $position === 1, 'position' => $position]);
            }
        });
        $this->loadModules();
    }

    public function addOption(int $moduleIndex, int $questionIndex): void
    {
        $question = $this->questionAt($moduleIndex, $questionIndex);
        QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => __('New option'), 'is_correct' => false, 'position' => $question->options()->count() + 1]);
        $this->loadModules();
    }

    public function selectSingleCorrect(int $moduleIndex, int $questionIndex, int $optionIndex): void
    {
        $question = $this->questionAt($moduleIndex, $questionIndex);
        $option = $this->optionAt($moduleIndex, $questionIndex, $optionIndex);
        DB::transaction(function () use ($question, $option): void {
            $question->options()->update(['is_correct' => false]);
            $option->update(['is_correct' => true]);
        });
        $this->loadModules();
    }

    public function requestUpload(int $moduleIndex, RequestVideoUpload $action, PlatformAccess $access): string
    {
        $access->authorize();
        $upload = $action->handle($this->moduleAt($moduleIndex));
        $this->loadModules();

        return $upload->uploadUrl;
    }

    public function uploadCompleted(int $moduleIndex, PlatformAccess $access): void
    {
        $access->authorize();
        $this->moduleAt($moduleIndex)->video?->update(['status' => VideoStatus::Processing]);
        $this->loadModules();
    }

    public function openEditorVideoLibrary(string $model, VideoLibrary $library, PlatformAccess $access): void
    {
        $access->authorize();
        abort_unless(preg_match('/^modules\.(\d+)\.content_markdown$/', $model, $matches) === 1, 422);
        $this->moduleAt((int) $matches[1]);
        $this->videoEditorModel = $model;
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
        $access->authorize();
        abort_unless(preg_match('/^modules\.(\d+)\.content_markdown$/', $this->videoEditorModel, $matches) === 1, 422);
        abort_unless(collect($this->videoLibraryItems)->contains(fn (array $item): bool => hash_equals((string) $item['asset_id'], $assetId) && $item['status'] === VideoStatus::Ready->value), 404);

        $item = collect($this->videoLibraryItems)->first(fn (array $item): bool => hash_equals((string) $item['asset_id'], $assetId));
        $action->handle($this->moduleAt((int) $matches[1]), $assetId, allowAnyOwner: true);
        $this->dispatch('oceanix:insert-video', model: $this->videoEditorModel, previewUrl: $item['preview_url'], posterUrl: $item['thumbnail_url'], title: $item['title'], aspectRatio: $item['aspect_ratio']);
        $this->videoLibraryOpen = false;
        $this->loadModules();
    }

    public function openImageLibrary(string $model, PlatformAccess $access): void
    {
        $access->authorize();
        abort_unless(preg_match('/^modules\.\d+\.content_markdown$/', $model) === 1, 422);
        $this->imageEditorModel = $model;
        $this->imageLibraryOpen = true;
        $this->resetValidation('contentImageUpload');
    }

    public function uploadContentImage(PlatformAccess $access): void
    {
        $access->authorize();
        $this->validate(['contentImageUpload' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240']]);

        $upload = $this->contentImageUpload;
        $path = $upload->storePublicly('content-images', 'public');
        abort_if($path === false, 500);

        $image = ContentImage::query()->create([
            'company_id' => null,
            'is_shared' => true,
            'name' => $upload->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $upload->getSize(),
        ]);

        $this->reset('contentImageUpload');
        $this->selectContentImage($image->id, $access);
    }

    public function selectContentImage(int $imageId, PlatformAccess $access): void
    {
        $access->authorize();
        $image = ContentImage::query()->where('is_shared', true)->findOrFail($imageId);
        abort_unless(preg_match('/^modules\.(\d+)\.content_markdown$/', $this->imageEditorModel, $matches) === 1, 422);

        $alt = pathinfo($image->name, PATHINFO_FILENAME);
        $this->dispatch('oceanix:insert-image', model: $this->imageEditorModel, url: $image->url(), alt: $alt);
        $this->imageLibraryOpen = false;
    }

    public function publish(PublishSharedCourseDraft $action, PlatformAccess $access): void
    {
        $actor = $access->authorize();
        try {
            $action->handle($this->version, $actor, $this->restartInProgress);
        } catch (CoursePublicationException|LogicException $exception) {
            $this->addError('publish', $exception instanceof CoursePublicationException ? ($exception->problems[0] ?? $exception->getMessage()) : $exception->getMessage());

            return;
        }
        session()->flash('status', __('Version :number published', ['number' => $this->version->version_number]));
        $this->redirect(route('platform.shared-courses.show', ['course' => $this->course]), navigate: true);
    }

    public function with(SharedContentCatalog $catalog): array
    {
        $options = $catalog->availableModules($this->moduleSearch)->map(fn (ModuleVersion $module): string => sprintf(
            '<option value="%d">%s · %s</option>', $module->id, e($module->title), e($module->code),
        ))->implode('');

        return [
            'impact' => $catalog->coursePublicationImpact($this->course),
            'availableModuleOptions' => new HtmlString($options),
            'contentImages' => ContentImage::query()->where('is_shared', true)->latest()->limit(40)->get(),
        ];
    }

    private function prepareModuleDrafts(CreateModuleDraft $action, $actor): void
    {
        foreach ($this->version->moduleCompositions()->with('moduleVersion')->get() as $composition) {
            $source = $composition->moduleVersion;
            if ($source->status === ModuleVersionStatus::Draft) {
                continue;
            }
            $draft = ModuleVersion::query()->where('lineage_uuid', $source->lineage_uuid)->where('status', ModuleVersionStatus::Draft->value)->first() ?? $action->handle($source, $actor);
            $composition->update(['module_version_id' => $draft->id]);
        }
    }

    private function loadModules(): void
    {
        $renderer = app(LessonContentRenderer::class);
        $this->modules = $this->version->moduleCompositions()->with(['moduleVersion.video', 'moduleVersion.questions.options'])->get()->map(fn ($composition) => [
            'composition_id' => $composition->id, 'id' => $composition->moduleVersion->id, 'title' => $composition->moduleVersion->title,
            'description' => $composition->moduleVersion->description, 'content_markdown' => $renderer->editorContent((string) $composition->moduleVersion->content_markdown),
            'minimum_watch_percentage' => $composition->moduleVersion->minimum_watch_percentage, 'passing_score' => $composition->moduleVersion->passing_score,
            'video' => $composition->moduleVersion->video ? ['status_label' => $composition->moduleVersion->video->status->label(), 'duration' => $composition->moduleVersion->video->formattedDuration(), 'preview' => rescue(fn (): ?array => app(VideoLibrary::class)->preview($composition->moduleVersion->video), null, report: false)] : null,
            'questions' => $composition->moduleVersion->questions->map(fn ($q) => ['id' => $q->id, 'prompt' => $q->prompt, 'type' => $q->type->value, 'max_attempts' => $q->max_attempts, 'options' => $q->options->map(fn ($o) => ['id' => $o->id, 'text' => $o->text, 'is_correct' => $o->is_correct])->all()])->all(),
        ])->all();
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

    private function moduleAt(int $index): ModuleVersion
    {
        abort_unless(isset($this->modules[$index]), 404);

        return ModuleVersion::query()->whereKey($this->modules[$index]['id'])->where('status', ModuleVersionStatus::Draft->value)->firstOrFail();
    }

    private function questionAt(int $moduleIndex, int $questionIndex): Question
    {
        $module = $this->moduleAt($moduleIndex);
        abort_unless(isset($this->modules[$moduleIndex]['questions'][$questionIndex]), 404);

        return $module->questions()->whereKey($this->modules[$moduleIndex]['questions'][$questionIndex]['id'])->firstOrFail();
    }

    private function optionAt(int $moduleIndex, int $questionIndex, int $optionIndex): QuestionOption
    {
        $question = $this->questionAt($moduleIndex, $questionIndex);
        abort_unless(isset($this->modules[$moduleIndex]['questions'][$questionIndex]['options'][$optionIndex]), 404);

        return $question->options()->whereKey($this->modules[$moduleIndex]['questions'][$questionIndex]['options'][$optionIndex]['id'])->firstOrFail();
    }
};
?>

<div class="admin-page space-y-7" x-on:oceanix-open-image-library.window="$wire.openImageLibrary($event.detail.model)" x-on:oceanix-open-video-library.window="$wire.openEditorVideoLibrary($event.detail.model)">
    <x-page-hero :kicker="__('Shared course draft')" :title="$courseForm['title']" :description="__('Edit the complete course on one continuous screen. Module versions are managed automatically.')">
        <span class="status-pill status-pill--accent">{{ __('Shared') }}</span><flux:button :href="route('platform.shared-courses.show', ['course' => $course])" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
    </x-page-hero>
    @error('publish') <flux:callout variant="danger" :heading="$message" /> @enderror

    <section class="detail-card space-y-4">
        <h2 class="detail-card-title">{{ __('Course details') }}</h2>
        <div class="grid gap-4 lg:grid-cols-2"><flux:input wire:model.blur="courseForm.code" :label="__('Code')" /><flux:input wire:model.blur="courseForm.title" :label="__('Title')" /></div>
        <flux:textarea wire:model.blur="courseForm.description" :label="__('Description')" rows="2" />
        <flux:textarea wire:model.blur="versionForm.description" :label="__('Description shown to the employee')" rows="2" />
    </section>

    <section class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="admin-kicker">{{ __('Content') }}</p><h2 class="text-2xl font-bold">{{ __('Modules') }}</h2></div><flux:input wire:model.live.debounce.300ms="moduleSearch" class="w-72" icon="magnifying-glass" :placeholder="__('Search shared modules')" /></div>
        @if ($modules === [])
            <div class="rounded-[20px] border border-dashed border-[#d7dee3] bg-white/60 p-8 text-center">
                <span class="mx-auto grid size-11 place-items-center rounded-2xl bg-[#eef3f6] text-[#7d878e]"><flux:icon.rectangle-stack class="size-5" /></span>
                <p class="mt-4 text-base font-bold text-[#262d33]">{{ __('No modules selected') }}</p>
                <p class="mx-auto mt-1 max-w-md text-sm leading-6 text-[#6f797f]">{{ __('Add a published shared module below to start building this course.') }}</p>
            </div>
        @endif
        @if ($modules !== [])
        @foreach ($modules as $moduleIndex => $module)
            <article class="detail-card" wire:key="shared-course-module-{{ $module['id'] }}">
                <div class="flex items-center gap-3"><button type="button" wire:click="toggleModule({{ $module['id'] }})" class="flex flex-1 items-center justify-between gap-4 text-left"><div><p class="font-bold">{{ $module['title'] }}</p><p class="text-xs text-[#8a9298]">{{ $module['video'] ? $module['video']['status_label'].' · '.$module['video']['duration'] : __('No video') }} · {{ trans_choice('ui.questions_count', count($module['questions']), ['count' => count($module['questions'])]) }}</p></div><flux:icon.chevron-down class="size-5" /></button><flux:button wire:click="removeModule({{ $moduleIndex }})" variant="ghost" icon="trash" :aria-label="__('Remove module')" /></div>
                @if (in_array($module['id'], $expanded, true))
                    <div class="mt-5 space-y-5 border-t border-[#e5eaed] pt-5">
                        <div class="grid gap-4 lg:grid-cols-2"><flux:input wire:model.blur="modules.{{ $moduleIndex }}.title" :label="__('Module title')" /><flux:textarea wire:model.blur="modules.{{ $moduleIndex }}.description" :label="__('Description')" rows="2" /></div>
                        <flux:editor
                            wire:model.live.debounce.500ms="modules.{{ $moduleIndex }}.content_markdown"
                            data-oceanix-editor-model="modules.{{ $moduleIndex }}.content_markdown"
                            data-oceanix-video-preview-url="{{ data_get($module, 'video.preview.preview_url') }}"
                            data-oceanix-video-poster-url="{{ data_get($module, 'video.preview.poster_url') }}"
                            data-oceanix-video-title="{{ $module['title'] }}"
                            data-oceanix-video-aspect-ratio="{{ data_get($module, 'video.preview.aspect_ratio', '16/9') }}"
                            class="oceanix-content-editor"
                            :label="__('Module content')"
                            toolbar="heading | bold italic underline strike | bullet ordered blockquote link | align | image image-left image-center image-right image-size video ~ fullscreen undo redo" />
                        <div class="grid gap-4 sm:grid-cols-2"><flux:input type="number" wire:model.blur="modules.{{ $moduleIndex }}.minimum_watch_percentage" :label="__('Watch threshold (%)')" /><flux:input type="number" wire:model.blur="modules.{{ $moduleIndex }}.passing_score" :label="__('Passing score (%)')" /></div>
                        <div class="flex items-center justify-between rounded-[18px] bg-[#f7f9fa] p-4" x-data="lessonVideoUpload({{ $moduleIndex }}, {{ Js::from(['fileTooLarge' => __('This video is larger than 200 MB. Select a smaller file.')]) }})"><div><p class="font-bold">{{ __('Video') }}</p><p class="text-xs text-[#8a9298]">{{ $module['video'] ? $module['video']['status_label'].' · '.$module['video']['duration'] : __('No video attached') }}</p><p x-show="uploading" x-text="`${progress}%`"></p></div><div><input type="file" accept="video/*" class="hidden" x-ref="file" @change="start($event)"><flux:button x-on:click="$refs.file.click()" variant="ghost">{{ $module['video'] ? __('Replace video') : __('Upload video') }}</flux:button></div></div>
                        <div>
                            <div class="flex items-center justify-between"><p class="font-bold">{{ __('Assessment') }}</p><flux:button wire:click="addQuestion({{ $moduleIndex }})" variant="ghost" icon="plus">{{ __('Add question') }}</flux:button></div>
                            <div class="mt-3 space-y-3">
                                @foreach ($module['questions'] as $questionIndex => $question)
                                    <div class="rounded-[18px] border border-[#e4e9ec] p-4" wire:key="question-{{ $question['id'] }}">
                                        <div class="grid gap-3 sm:grid-cols-[1fr_140px]"><flux:input wire:model.blur="modules.{{ $moduleIndex }}.questions.{{ $questionIndex }}.prompt" :label="__('Question')" /><flux:input type="number" wire:model.blur="modules.{{ $moduleIndex }}.questions.{{ $questionIndex }}.max_attempts" :label="__('Attempts')" /></div>
                                        <div class="mt-3 space-y-2">
                                            @foreach ($question['options'] as $optionIndex => $option)
                                                <div class="flex items-center gap-2" wire:key="option-{{ $option['id'] }}"><input type="radio" wire:click="selectSingleCorrect({{ $moduleIndex }}, {{ $questionIndex }}, {{ $optionIndex }})" @checked($option['is_correct']) name="correct-{{ $question['id'] }}"><flux:input wire:model.blur="modules.{{ $moduleIndex }}.questions.{{ $questionIndex }}.options.{{ $optionIndex }}.text" class="flex-1" /></div>
                                            @endforeach
                                        </div>
                                        <flux:button wire:click="addOption({{ $moduleIndex }}, {{ $questionIndex }})" variant="ghost" size="sm" class="mt-3">{{ __('Add option') }}</flux:button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            </article>
        @endforeach
        @endif
        <div class="flex flex-wrap items-end gap-3 rounded-[18px] border border-[#dde3e7] bg-white p-4">
            <label class="min-w-72 flex-1 text-sm font-semibold">{{ __('Add an existing shared module') }}
                <select wire:model="selectedModuleId" class="mt-2 w-full rounded-xl border border-[#cfd8dd] bg-white px-3 py-2"><option value="">{{ __('Choose a module') }}</option>{!! $availableModuleOptions !!}</select>
            </label>
            <flux:button wire:click="addSelectedModule" variant="ghost" :disabled="$selectedModuleId === null">{{ __('Add module') }}</flux:button>
        </div>
    </section>

    <section class="detail-card space-y-5"><h2 class="detail-card-title">{{ __('Publish') }}</h2><div class="grid gap-3 sm:grid-cols-2"><div class="metric-card metric-card--slate"><p class="metric-label">{{ __('Not started') }}</p><p class="metric-value">{{ $impact['not_started'] }}</p></div><div class="metric-card metric-card--amber"><p class="metric-label">{{ __('In progress') }}</p><p class="metric-value">{{ $impact['in_progress'] }}</p></div></div><flux:checkbox wire:model="restartInProgress" :label="__('Restart in-progress assignments')" /><flux:button wire:click="publish" wire:loading.attr="disabled" variant="primary">{{ __('Publish course and module changes') }}</flux:button></section>

    <flux:modal wire:model.self="imageLibraryOpen" class="max-w-4xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Image library') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Upload an image from your computer or reuse an image already in the shared library.') }}</flux:text>
            </div>

            <form wire:submit="uploadContentImage" class="rounded-[18px] border border-dashed border-[#cfd8dd] bg-[#f7f9fa] p-5">
                <label class="block text-sm font-bold text-[#262d33]">{{ __('Upload from computer') }}
                    <input wire:model="contentImageUpload" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="mt-3 block w-full rounded-xl border border-[#cfd8dd] bg-white p-3 text-sm">
                </label>
                <p class="mt-2 text-xs text-[#7d878e]">{{ __('JPG, PNG, WebP or GIF, up to 10 MB.') }}</p>
                @error('contentImageUpload') <p class="mt-2 text-sm font-medium text-red-600">{{ $message }}</p> @enderror
                <flux:button type="submit" wire:loading.attr="disabled" wire:target="contentImageUpload,uploadContentImage" variant="primary" class="mt-4">{{ __('Upload and use image') }}</flux:button>
            </form>

            <div>
                <h3 class="text-sm font-bold text-[#262d33]">{{ __('Shared gallery') }}</h3>
                @if ($contentImages->isEmpty())
                    <div class="mt-3 rounded-[18px] border border-dashed border-[#d7dee3] p-6 text-center text-sm text-[#6f797f]">{{ __('No images have been uploaded yet.') }}</div>
                @else
                    <div class="mt-3 grid max-h-[45vh] grid-cols-2 gap-3 overflow-y-auto pr-1 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($contentImages as $image)
                            <button type="button" wire:click="selectContentImage({{ $image->id }})" class="group overflow-hidden rounded-2xl border border-[#dde3e7] bg-white text-left" wire:key="content-image-{{ $image->id }}">
                                <img src="{{ $image->url() }}" alt="" class="aspect-[4/3] w-full bg-[#eef3f6] object-cover transition group-hover:scale-[1.02]">
                                <span class="block truncate px-3 py-2 text-xs font-semibold text-[#4f5960]">{{ $image->name }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="videoLibraryOpen" class="max-w-4xl">
        <div class="space-y-5">
            <div><flux:heading size="lg">{{ __('Video library') }}</flux:heading><flux:text class="mt-2">{{ __('Select a ready video from the platform Cloudflare library.') }}</flux:text></div>
            <div class="flex gap-2"><flux:input wire:model="videoLibrarySearch" wire:keydown.enter="searchVideoLibrary" class="flex-1" :label="__('Search videos')" /><flux:button wire:click="searchVideoLibrary" class="self-end" variant="ghost">{{ __('Search') }}</flux:button></div>
            @if ($videoLibraryError)
                <flux:callout variant="danger" :heading="$videoLibraryError" />
            @elseif ($videoLibraryItems === [])
                <x-empty-state icon="film" :title="__('No videos found')" :description="__('Upload a video and it will appear here.')" />
            @else
                <div class="grid max-h-[55vh] gap-3 overflow-y-auto sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($videoLibraryItems as $item)
                        <article class="overflow-hidden rounded-2xl border border-[#dde3e7] bg-white" wire:key="shared-video-{{ $item['asset_id'] }}">
                            <div class="aspect-video bg-[#e8eef1]">@if ($item['thumbnail_url'])<img src="{{ $item['thumbnail_url'] }}" alt="" class="size-full object-cover">@else<span class="grid size-full place-items-center"><flux:icon.film class="size-8 text-[#8a9298]" /></span>@endif</div>
                            <div class="space-y-2 p-3"><p class="truncate text-sm font-bold">{{ $item['title'] }}</p><p class="text-xs text-[#7d878e]">{{ $item['duration'] }} · {{ $item['status_label'] }}</p><flux:button wire:click="selectLibraryVideo('{{ $item['asset_id'] }}')" variant="primary" size="sm" class="w-full" :disabled="$item['status'] !== App\Enums\VideoStatus::Ready->value">{{ __('Use video') }}</flux:button></div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </flux:modal>
</div>
