<?php

use App\Actions\Courses\PublishSharedCourseDraft;
use App\Actions\Courses\PrepareSharedCourseEditor;
use App\Actions\Courses\RemoveSharedCourseModule;
use App\Actions\Courses\SaveSharedCourseEditorDraft;
use App\Actions\Modules\CreateAndAttachSharedModule;
use App\Actions\Videos\LinkExistingVideo;
use App\Actions\Videos\RequestVideoUpload;
use App\Enums\CourseStatus;
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
use App\Models\Video;
use App\Services\Courses\LessonContentRenderer;
use App\Services\Modules\SharedModuleDraftWriter;
use App\Services\Platform\PlatformAccess;
use App\Services\SharedContent\SharedContentCatalog;
use App\Services\Video\VideoLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public array $assessmentDirty = [];

    public array $assessmentStatus = [];

    public array $assessmentErrors = [];

    public bool $editorDirty = false;

    public string $courseRevision = '';

    public array $moduleRevisions = [];

    public array $contentDirty = [];

    public ?string $saveError = null;

    public bool $uploadInProgress = false;

    public array $activeUploads = [];

    public string $moduleSearch = '';

    public ?int $selectedModuleId = null;

    public bool $newModuleModalOpen = false;

    public array $newModuleForm = ['code' => '', 'title' => '', 'description' => ''];

    public ?string $newModuleError = null;

    public bool $confirmingPublish = false;

    public bool $confirmingModuleRemoval = false;

    public ?int $moduleRemovalIndex = null;

    public string $moduleRemovalReason = '';

    public string $moduleRemovalRevision = '';

    public bool $restartInProgress = false;

    public bool $imageLibraryOpen = false;

    public string $imageEditorModel = '';

    public $contentImageUpload;

    public bool $videoLibraryOpen = false;

    public string $videoEditorModel = '';

    public array $videoLibraryItems = [];

    public string $videoLibrarySearch = '';

    public ?string $videoLibraryError = null;

    public function mount(Course $course, PlatformAccess $access): void
    {
        $access->authorize();
        abort_unless($course->is_shared && $course->company_id === null, 404);
        $this->course = $course;
        $this->version = $course->versions()->where('status', CourseVersionStatus::Draft->value)->where('publication_kind', 'manual')->firstOrFail();
        abort_if($this->version->moduleCompositions()->whereHas('moduleVersion', fn ($query) => $query->where('status', '!=', ModuleVersionStatus::Draft->value))->exists(), 409, __('Open this editor from the course page so its module drafts can be prepared safely.'));
        $this->courseForm = ['code' => $course->code, 'title' => $course->title, 'description' => $course->description];
        $this->versionForm = ['description' => $this->version->description];
        $this->loadModules();
        $this->expanded = collect($this->modules)->pluck('id')->all();
        $this->assessmentDirty = collect($this->modules)->mapWithKeys(fn (array $module): array => [$module['id'] => false])->all();
        $this->contentDirty = collect($this->modules)->mapWithKeys(fn (array $module): array => [$module['id'] => false])->all();
    }

    public function updated(string $property, mixed $value): void
    {
        if (preg_match('/^modules\.(\d+)\.content_markdown$/', $property, $matches) === 1 && isset($this->modules[(int) $matches[1]])) {
            $this->contentDirty[$this->modules[(int) $matches[1]]['id']] = true;
        }
    }

    public function toggleModule(int $id): void
    {
        $this->expanded = in_array($id, $this->expanded, true) ? array_values(array_diff($this->expanded, [$id])) : [...$this->expanded, $id];
    }

    public function addModule(int $moduleVersionId, SharedContentCatalog $catalog, PrepareSharedCourseEditor $prepareEditor, PlatformAccess $access): void
    {
        $access->authorize();
        if ($this->blockStructuralChange()) {
            return;
        }
        $actor = $access->authorize();
        DB::transaction(function () use ($moduleVersionId, $catalog, $prepareEditor, $actor): void {
            $course = Course::query()->lockForUpdate()->whereKey($this->course->id)->whereNull('company_id')->where('is_shared', true)->where('status', '!=', CourseStatus::Archived->value)->firstOrFail();
            $version = CourseVersion::query()->lockForUpdate()->whereKey($this->version->id)->where('course_id', $course->id)->where('status', CourseVersionStatus::Draft->value)->where('publication_kind', 'manual')->firstOrFail();
            $source = $catalog->availableModules()->firstWhere('id', $moduleVersionId);
            abort_unless($source instanceof ModuleVersion, 404);
            abort_if($version->moduleCompositions()->where('lesson_id', $moduleVersionId)->exists(), 422);
            CourseVersionModule::query()->create(['course_version_id' => $version->id, 'module_version_id' => $source->id, 'position' => $version->moduleCompositions()->count() + 1, 'is_required' => true]);
            $prepareEditor->handle($course, $actor, $prepareEditor->revision($course, $version));
        });
        $this->loadModules();
        $this->expanded = collect($this->modules)->pluck('id')->all();
    }

    public function addSelectedModule(SharedContentCatalog $catalog, PrepareSharedCourseEditor $prepareEditor, PlatformAccess $access): void
    {
        abort_if($this->selectedModuleId === null, 422);
        $this->addModule($this->selectedModuleId, $catalog, $prepareEditor, $access);
        $this->selectedModuleId = null;
    }

    public function openNewModuleModal(): void
    {
        $this->reset('newModuleForm');
        $this->newModuleForm = ['code' => '', 'title' => '', 'description' => ''];
        $this->newModuleError = null;
        $this->resetValidation('newModuleForm');
        $this->newModuleModalOpen = true;
    }

    public function createNewModule(CreateAndAttachSharedModule $action, PlatformAccess $access): void
    {
        if ($this->blockStructuralChange()) {
            return;
        }
        $this->newModuleError = null;
        $data = $this->validate([
            'newModuleForm.code' => ['required', 'string', 'max:80'],
            'newModuleForm.title' => ['required', 'string', 'max:200'],
            'newModuleForm.description' => ['nullable', 'string', 'max:2000'],
        ]);

        $actor = $access->authorize();

        try {
            $module = $action->handle(
                $this->version,
                $actor,
                $data['newModuleForm']['code'],
                $data['newModuleForm']['title'],
                $data['newModuleForm']['description'] ?: null,
            );
        } catch (ValidationException $exception) {
            $this->addError('newModuleForm.code', $exception->errors()['code'][0] ?? __('A shared module with this code already exists.'));

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->newModuleError = __('The shared module could not be created. Check the course draft and try again.');

            return;
        }

        $this->loadModules();
        $this->expanded = [...array_values(array_diff($this->expanded, [$module->id])), $module->id];
        $this->newModuleModalOpen = false;
        $this->newModuleForm = ['code' => '', 'title' => '', 'description' => ''];
        session()->flash('status', __('Shared module created and added to the course.'));
        $this->dispatch('shared-module-created', moduleId: $module->id);
    }

    public function confirmModuleRemoval(int $moduleIndex, RemoveSharedCourseModule $action, PlatformAccess $access): void
    {
        $access->authorize();
        if ($this->blockStructuralChange()) {
            return;
        }
        abort_unless(isset($this->modules[$moduleIndex]), 404);
        $this->moduleRemovalIndex = $moduleIndex;
        $this->moduleRemovalReason = '';
        $this->moduleRemovalRevision = $action->revision($this->version->fresh());
        $this->confirmingModuleRemoval = true;
    }

    public function removeModule(RemoveSharedCourseModule $action, PlatformAccess $access): void
    {
        $actor = $access->authorize();
        $this->validate(['moduleRemovalReason' => ['required', 'string', 'max:500']]);
        abort_unless($this->moduleRemovalIndex !== null && isset($this->modules[$this->moduleRemovalIndex]), 404);
        $module = $this->modules[$this->moduleRemovalIndex];
        $this->version = $action->handle($this->version, $module['composition_id'], $actor, $this->moduleRemovalReason, $this->moduleRemovalRevision);
        $this->loadModules();
        $this->reset('confirmingModuleRemoval', 'moduleRemovalIndex', 'moduleRemovalReason', 'moduleRemovalRevision');
        session()->flash('status', __('Module removed from the draft. Its content was not deleted.'));
    }

    public function addQuestion(int $moduleIndex, PlatformAccess $access): void
    {
        $access->authorize();
        $module = $this->moduleAt($moduleIndex);
        $this->modules[$moduleIndex]['questions'][] = $this->newQuestion();
        $this->markAssessmentDirty($module->id);
    }

    public function addOption(int $moduleIndex, int $questionIndex, PlatformAccess $access): void
    {
        $access->authorize();
        $module = $this->moduleAt($moduleIndex);
        abort_unless(isset($this->modules[$moduleIndex]['questions'][$questionIndex]), 404);
        $this->modules[$moduleIndex]['questions'][$questionIndex]['options'][] = $this->newOption(count($this->modules[$moduleIndex]['questions'][$questionIndex]['options']) + 1, false);
        $this->markAssessmentDirty($module->id);
    }

    public function removeQuestion(int $moduleIndex, int $questionIndex, PlatformAccess $access): void
    {
        $access->authorize();
        $module = $this->moduleAt($moduleIndex);
        abort_unless(isset($this->modules[$moduleIndex]['questions'][$questionIndex]), 404);
        array_splice($this->modules[$moduleIndex]['questions'], $questionIndex, 1);
        $this->markAssessmentDirty($module->id);
    }

    public function removeOption(int $moduleIndex, int $questionIndex, int $optionIndex, PlatformAccess $access): void
    {
        $access->authorize();
        $module = $this->moduleAt($moduleIndex);
        abort_unless(isset($this->modules[$moduleIndex]['questions'][$questionIndex]['options'][$optionIndex]), 404);
        array_splice($this->modules[$moduleIndex]['questions'][$questionIndex]['options'], $optionIndex, 1);
        $this->markAssessmentDirty($module->id);
    }

    public function markAssessmentDirty(int $moduleId): void
    {
        abort_unless(collect($this->modules)->contains('id', $moduleId), 404);
        $this->assessmentDirty[$moduleId] = true;
        $this->assessmentStatus[$moduleId] = 'unsaved';
        unset($this->assessmentErrors[$moduleId]);
        $this->editorDirty = true;
        $this->dispatch('assessment-dirty', moduleId: $moduleId);
    }

    public function saveDraft(bool $close, SaveSharedCourseEditorDraft $action, PlatformAccess $access): void
    {
        if ($close && $this->uploadInProgress) {
            $this->saveError = __('Wait for active uploads to finish before closing.');

            return;
        }
        if (! $this->editorDirty) {
            if ($close) {
                $this->redirect(route('platform.shared-courses.show', ['course' => $this->course]), navigate: true);
            }

            return;
        }
        $this->saveError = null;
        $actor = $access->authorize();
        try {
            $modules = collect($this->modules)->map(fn (array $module): array => [...$module, 'content_dirty' => $this->contentDirty[$module['id']] ?? false])->all();
            $revisions = $action->handle($this->course, $this->version, $actor, $this->courseForm, $this->versionForm, $modules, $this->courseRevision, $this->moduleRevisions);
        } catch (ValidationException $exception) {
            $this->saveError = collect($exception->errors())->flatten()->first() ?? __('The draft could not be saved.');
            $this->dispatch('editor-save-finished');

            return;
        } catch (LogicException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->saveError = __('The draft could not be saved. Try again.');
            $this->dispatch('editor-save-finished');

            return;
        }
        $this->course->refresh();
        $this->version->refresh();
        $this->loadModules();
        $this->editorDirty = false;
        $this->contentDirty = collect($this->modules)->mapWithKeys(fn (array $module): array => [$module['id'] => false])->all();
        foreach (array_keys($this->assessmentDirty) as $moduleId) {
            $this->assessmentDirty[$moduleId] = false;
            $this->assessmentStatus[$moduleId] = 'saved';
            $this->dispatch('assessment-saved', moduleId: $moduleId);
        }
        $this->dispatch('editor-saved');
        $this->dispatch('editor-save-finished');
        if ($close) {
            $this->redirect(route('platform.shared-courses.show', ['course' => $this->course]), navigate: true);
        }
    }

    public function requestUpload(int $moduleIndex, RequestVideoUpload $action, PlatformAccess $access): array
    {
        $actor = $access->authorize();
        $module = $this->moduleAt($moduleIndex);
        $upload = $action->handle($module, platformActor: $actor);
        $token = (string) Str::uuid();
        $this->activeUploads[$token] = ['module_id' => $module->id, 'video_id' => $upload->videoId, 'provider' => $upload->provider, 'asset_id' => $upload->assetId];
        $this->syncUploadState();
        $this->reloadModulesPreservingDraftState();

        return ['url' => $upload->uploadUrl, 'token' => $token];
    }

    public function uploadCompleted(int $moduleIndex, PlatformAccess $access, VideoLibrary $library, ?string $uploadToken = null): void
    {
        $access->authorize();
        $identity = $this->uploadIdentity($moduleIndex, $uploadToken);
        $module = $this->moduleById($identity['module_id']);
        DB::transaction(function () use ($module, $identity): void {
            $video = Video::query()->lockForUpdate()->whereKey($identity['video_id'])->where('lesson_id', $module->id)->where('provider', $identity['provider'])->where('provider_asset_id', $identity['asset_id'])->firstOrFail();
            $video->update(['status' => VideoStatus::Processing]);
        });
        $this->finishUpload($moduleIndex, $uploadToken);
        $this->reloadModulesPreservingDraftState();

        if ($this->videoLibraryOpen) {
            $this->videoLibrarySearch = '';
            $this->loadVideoLibrary($library);
        }
    }

    public function uploadFailed(int $moduleIndex, PlatformAccess $access, ?string $uploadToken = null): void
    {
        $access->authorize();
        $identity = $this->uploadIdentity($moduleIndex, $uploadToken);
        $module = $this->moduleById($identity['module_id']);
        Video::query()->whereKey($identity['video_id'])->where('lesson_id', $module->id)->where('provider', $identity['provider'])->where('provider_asset_id', $identity['asset_id'])->where('status', VideoStatus::Uploading->value)->update(['status' => VideoStatus::Failed]);
        $this->finishUpload($moduleIndex, $uploadToken);
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
        $actor = $access->authorize();
        abort_unless(preg_match('/^modules\.(\d+)\.content_markdown$/', $this->videoEditorModel, $matches) === 1, 422);
        abort_unless(collect($this->videoLibraryItems)->contains(fn (array $item): bool => hash_equals((string) $item['asset_id'], $assetId) && $item['status'] === VideoStatus::Ready->value), 404);

        $item = collect($this->videoLibraryItems)->first(fn (array $item): bool => hash_equals((string) $item['asset_id'], $assetId));
        $action->handle($this->moduleAt((int) $matches[1]), $assetId, allowAnyOwner: true, platformActor: $actor);
        $this->dispatch('oceanix:insert-video', model: $this->videoEditorModel, previewUrl: $item['preview_url'], posterUrl: $item['thumbnail_url'], title: $item['title'], aspectRatio: $item['aspect_ratio']);
        $this->videoLibraryOpen = false;
        $this->reloadModulesPreservingDraftState();
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
        $disk = (string) config('filesystems.content_images_disk', 'public');
        $path = $upload->store((string) config('filesystems.content_images_path', 'content-images'), $disk);
        abort_if($path === false, 500);

        $image = ContentImage::query()->create([
            'company_id' => null,
            'is_shared' => true,
            'name' => $upload->getClientOriginalName(),
            'disk' => $disk,
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
        if ($this->editorDirty || $this->uploadInProgress || in_array(true, $this->assessmentDirty, true)) {
            $this->addError('publish', __('Save all assessment changes before publishing.'));

            return;
        }
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
            'canRemoveModule' => app(PlatformAccess::class)->account() !== null,
        ];
    }

    private function loadModules(): void
    {
        $renderer = app(LessonContentRenderer::class);
        $this->modules = $this->version->moduleCompositions()->with(['moduleVersion.video', 'moduleVersion.questions.options'])->get()->map(fn ($composition) => [
            'composition_id' => $composition->id, 'id' => $composition->moduleVersion->id, 'code' => $composition->moduleVersion->code,
            'title' => $composition->moduleVersion->title, 'version_number' => $composition->moduleVersion->version_number,
            'status' => $composition->moduleVersion->status->value,
            'description' => $composition->moduleVersion->description, 'content_markdown' => $renderer->editorContent((string) $composition->moduleVersion->content_markdown),
            'minimum_watch_percentage' => $composition->moduleVersion->minimum_watch_percentage, 'passing_score' => $composition->moduleVersion->passing_score,
            'video' => $composition->moduleVersion->video ? ['status_label' => $composition->moduleVersion->video->status->label(), 'duration' => $composition->moduleVersion->video->formattedDuration(), 'preview' => rescue(fn (): ?array => app(VideoLibrary::class)->preview($composition->moduleVersion->video), null, report: false)] : null,
            'questions' => $composition->moduleVersion->questions->map(fn ($q) => ['id' => $q->id, 'key' => "question-{$q->id}", 'prompt' => $q->prompt, 'type' => $q->type->value, 'max_attempts' => $q->max_attempts, 'options' => $q->options->map(fn ($o) => ['id' => $o->id, 'key' => "option-{$o->id}", 'text' => $o->text, 'is_correct' => $o->is_correct])->all()])->all(),
        ])->all();
        $this->courseRevision = app(SaveSharedCourseEditorDraft::class)->revision($this->course->fresh(), $this->version->fresh());
        $this->moduleRevisions = collect($this->modules)->mapWithKeys(fn (array $module): array => [$module['id'] => app(SharedModuleDraftWriter::class)->revision(ModuleVersion::query()->findOrFail($module['id']))])->all();
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

    private function newQuestion(): array
    {
        return ['id' => null, 'key' => 'question-'.Str::uuid(), 'prompt' => 'New question', 'type' => QuestionType::SingleChoice->value, 'max_attempts' => 3,
            'options' => [$this->newOption(1, true), $this->newOption(2, false)]];
    }

    private function newOption(int $number, bool $correct): array
    {
        return ['id' => null, 'key' => 'option-'.Str::uuid(), 'text' => "Option {$number}", 'is_correct' => $correct];
    }

    private function moduleAt(int $index): ModuleVersion
    {
        abort_unless(isset($this->modules[$index]), 404);

        $version = CourseVersion::query()->whereKey($this->version->id)->where('status', CourseVersionStatus::Draft->value)->where('publication_kind', 'manual')->whereHas('course', fn ($query) => $query->whereNull('company_id')->where('is_shared', true)->where('status', '!=', CourseStatus::Archived->value))->firstOrFail();
        $composition = $version->moduleCompositions()->whereKey($this->modules[$index]['composition_id'])->firstOrFail();

        return ModuleVersion::query()->whereKey($composition->module_version_id)->whereNull('company_id')->where('is_shared', true)->where('status', ModuleVersionStatus::Draft->value)->whereNull('lineage_archived_at')->firstOrFail();
    }

    private function moduleById(int $moduleId): ModuleVersion
    {
        $version = CourseVersion::query()->whereKey($this->version->id)->where('status', CourseVersionStatus::Draft->value)->where('publication_kind', 'manual')
            ->whereHas('course', fn ($query) => $query->whereNull('company_id')->where('is_shared', true)->where('status', '!=', CourseStatus::Archived->value))
            ->firstOrFail();
        abort_unless($version->moduleCompositions()->where('lesson_id', $moduleId)->exists(), 404);

        return ModuleVersion::query()->whereKey($moduleId)->whereNull('company_id')->where('is_shared', true)
            ->where('status', ModuleVersionStatus::Draft->value)->whereNull('lineage_archived_at')->firstOrFail();
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

    private function blockStructuralChange(): bool
    {
        if (! $this->editorDirty && ! $this->uploadInProgress) {
            return false;
        }

        $this->saveError = $this->uploadInProgress
            ? __('Wait for active uploads to finish before modifying the course structure.')
            : __('Save your changes before modifying the course structure.');

        return true;
    }

    private function reloadModulesPreservingDraftState(): void
    {
        $staged = collect($this->modules)->keyBy('id');
        $courseRevision = $this->courseRevision;
        $moduleRevisions = $this->moduleRevisions;
        $this->loadModules();

        if (! $this->editorDirty) {
            return;
        }

        foreach ($this->modules as $index => $module) {
            $previous = $staged->get($module['id']);
            if ($previous === null) {
                continue;
            }
            foreach (['title', 'description', 'content_markdown', 'minimum_watch_percentage', 'passing_score', 'questions'] as $field) {
                $this->modules[$index][$field] = $previous[$field];
            }
        }

        // Retained form values must keep the revisions they were originally based on.
        $this->courseRevision = $courseRevision;
        $this->moduleRevisions = $moduleRevisions;
    }

    private function finishUpload(int $moduleIndex, ?string $uploadToken): void
    {
        if ($uploadToken !== null) {
            if (isset($this->activeUploads[$uploadToken])) {
                unset($this->activeUploads[$uploadToken]);
            }
        } else {
            $moduleId = $this->modules[$moduleIndex]['id'] ?? null;
            $token = collect($this->activeUploads)->search(fn (array $upload): bool => $upload['module_id'] === $moduleId);
            if ($token !== false) {
                unset($this->activeUploads[$token]);
            }
        }

        $this->syncUploadState();
    }

    private function syncUploadState(): void
    {
        $this->uploadInProgress = $this->activeUploads !== [];
    }

    private function uploadIdentity(int $moduleIndex, ?string $uploadToken): array
    {
        abort_if($uploadToken === null, 422);
        $identity = $this->activeUploads[$uploadToken] ?? null;
        abort_unless(is_array($identity), 404);

        return $identity;
    }
};
?>

<div class="admin-page space-y-7" style="padding-bottom: calc(var(--editor-save-bar-height, 8rem) + 1rem);" x-data="{ pageDirty: false, saving: false, dirtyModules: {}, beforeUnloadHandler: null, saveBarObserver: null, markPageDirty(moduleId = null) { this.pageDirty = true; if (moduleId !== null) this.dirtyModules[moduleId] = true; $wire.set('editorDirty', true, false); if (moduleId !== null) $wire.set(`assessmentDirty.${moduleId}`, true, false); }, dirtyModuleCount() { return Object.keys(this.dirtyModules).length; }, hasOpenDialog() { return Boolean(document.querySelector('dialog[open], [role=dialog][aria-modal=true]:not([hidden])')); }, observeSaveBar(element) { this.saveBarObserver?.disconnect(); this.saveBarObserver = new ResizeObserver(entries => this.$root.style.setProperty('--editor-save-bar-height', `${entries[0].contentRect.height}px`)); this.saveBarObserver.observe(element); }, init() { this.beforeUnloadHandler = event => { if (this.pageDirty) { event.preventDefault(); event.returnValue = ''; } }; window.addEventListener('beforeunload', this.beforeUnloadHandler); this.keyHandler = event => { if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') { event.preventDefault(); if (! this.pageDirty || this.saving || this.hasOpenDialog()) return; this.saving = true; $wire.saveDraft(false); } }; window.addEventListener('keydown', this.keyHandler); }, destroy() { window.removeEventListener('beforeunload', this.beforeUnloadHandler); window.removeEventListener('keydown', this.keyHandler); this.saveBarObserver?.disconnect(); } }" x-on:editor-dirty="markPageDirty()" x-on:assessment-dirty="markPageDirty($event.detail.moduleId)" x-on:editor-saved.window="pageDirty = false; saving = false; dirtyModules = {}" x-on:editor-save-finished.window="saving = false" x-on:livewire:navigate.window="if (pageDirty && ! window.confirm({{ Js::from(__('You have unsaved changes. Leave without saving?')) }})) $event.preventDefault()" x-on:oceanix-open-image-library.window="$wire.openImageLibrary($event.detail.model)" x-on:oceanix-open-video-library.window="$wire.openEditorVideoLibrary($event.detail.model)" x-on:shared-module-created.window="$nextTick(() => document.getElementById(`shared-module-title-${$event.detail.moduleId}`)?.focus())">
    <x-page-hero :kicker="__('Shared course draft')" :title="$courseForm['title']" :description="__('Edit the complete course on one continuous screen. Module versions are managed automatically.')">
        <span class="status-pill status-pill--accent">{{ __('Shared') }}</span><flux:button :href="route('platform.shared-courses.show', ['course' => $course])" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
    </x-page-hero>
    @error('publish') <flux:callout variant="danger" :heading="$message" /> @enderror
    <x-status-message />

    <section class="detail-card space-y-4">
        <h2 class="detail-card-title">{{ __('Course details') }}</h2>
        <div class="grid gap-4 lg:grid-cols-2"><flux:input wire:model.defer="courseForm.code" x-on:input="$dispatch('editor-dirty')" :label="__('Code')" /><flux:input wire:model.defer="courseForm.title" x-on:input="$dispatch('editor-dirty')" :label="__('Title')" /></div>
        <flux:textarea wire:model.defer="courseForm.description" x-on:input="$dispatch('editor-dirty')" :label="__('Description')" rows="2" />
        <flux:textarea wire:model.defer="versionForm.description" x-on:input="$dispatch('editor-dirty')" :label="__('Description shown to the employee')" rows="2" />
    </section>

    <section class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div><p class="admin-kicker">{{ __('Content') }}</p><h2 class="text-2xl font-bold">{{ __('Modules') }}</h2></div>
            <flux:input wire:model.live.debounce.300ms="moduleSearch" class="w-full sm:w-72" icon="magnifying-glass" :label="__('Search shared modules')" :placeholder="__('Search by title or code')" />
        </div>
        @if ($modules === [])
            <div class="rounded-[20px] border border-dashed border-[#d7dee3] bg-white/60 p-8 text-center">
                <span class="mx-auto grid size-11 place-items-center rounded-2xl bg-[#eef3f6] text-[#7d878e]"><flux:icon.rectangle-stack class="size-5" /></span>
                <p class="mt-4 text-base font-bold text-[#262d33]">{{ __('No modules selected') }}</p>
                <p class="mx-auto mt-1 max-w-md text-sm leading-6 text-[#6f797f]">{{ __('Create a new shared module or add a published one to start building this course.') }}</p>
            </div>
        @endif
        @if ($modules !== [])
        @foreach ($modules as $moduleIndex => $module)
            <article class="detail-card" wire:key="shared-course-module-{{ $module['id'] }}" x-data="{ dirty: {{ Js::from($assessmentDirty[$module['id']] ?? false) }}, markDirty() { if (! this.dirty) { this.dirty = true; $dispatch('assessment-dirty', { moduleId: {{ $module['id'] }} }); } } }" x-on:assessment-saved.window="if ($event.detail.moduleId === {{ $module['id'] }}) dirty = false">
                <div class="flex items-start gap-3">
                    <button type="button" wire:click="toggleModule({{ $module['id'] }})" class="flex min-w-0 flex-1 items-center justify-between gap-4 text-left" aria-expanded="{{ in_array($module['id'], $expanded, true) ? 'true' : 'false' }}" aria-controls="shared-course-module-panel-{{ $module['id'] }}">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2"><p class="font-bold">{{ $module['title'] }}</p><span class="status-pill status-pill--accent">{{ __('Shared') }}</span><span class="status-pill">{{ __('Managed by platform') }}</span></div>
                            <p class="mt-1 text-xs text-[#8a9298]">{{ trans_choice('ui.questions_count', count($module['questions']), ['count' => count($module['questions'])]) }}</p>
                        </div>
                        <flux:icon.chevron-down class="size-5 shrink-0" />
                    </button>
                    @if ($canRemoveModule)<flux:button wire:click="confirmModuleRemoval({{ $moduleIndex }})" variant="ghost" icon="trash" :aria-label="__('Remove from course')" />@endif
                </div>
                @if (in_array($module['id'], $expanded, true))
                    <div id="shared-course-module-panel-{{ $module['id'] }}" class="mt-5 space-y-5 border-t border-[#e5eaed] pt-5">
                        <div class="grid gap-4 lg:grid-cols-2"><flux:input id="shared-module-title-{{ $module['id'] }}" wire:model.defer="modules.{{ $moduleIndex }}.title" x-on:input="markDirty" :label="__('Module title')" /><flux:textarea wire:model.defer="modules.{{ $moduleIndex }}.description" x-on:input="markDirty" :label="__('Description')" rows="2" /></div>
                        <flux:editor
                            wire:model.defer="modules.{{ $moduleIndex }}.content_markdown"
                            x-on:input="$wire.set('contentDirty.{{ $module['id'] }}', true, false); markDirty()"
                            data-oceanix-editor-model="modules.{{ $moduleIndex }}.content_markdown"
                            data-oceanix-video-preview-url="{{ data_get($module, 'video.preview.preview_url') }}"
                            data-oceanix-video-poster-url="{{ data_get($module, 'video.preview.poster_url') }}"
                            data-oceanix-video-title="{{ $module['title'] }}"
                            data-oceanix-video-aspect-ratio="{{ data_get($module, 'video.preview.aspect_ratio', '16/9') }}"
                            class="oceanix-content-editor"
                            :label="__('Module content')"
                            toolbar="heading | bold italic underline strike | bullet ordered blockquote link | align | image image-left image-center image-right image-size video ~ fullscreen undo redo" />
                        <flux:input type="number" wire:model.defer="modules.{{ $moduleIndex }}.passing_score" x-on:input="markDirty" :label="__('Passing score (%)')" />
                        <div>
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div><p class="font-bold">{{ __('Assessment') }}</p><p class="text-xs text-[#707a80]">{{ __('Assessment changes are saved explicitly.') }}</p></div>
                            </div>
                            @if (isset($assessmentErrors[$module['id']]))<flux:callout class="mt-3" variant="danger" :heading="$assessmentErrors[$module['id']]" />@endif
                            <div class="mt-3 space-y-3">
                                @if ($module['questions'] === [])<x-empty-state icon="question-mark-circle" :title="__('No assessment questions yet')" :description="__('Add a question to build this assessment.')" />@endif
                                @foreach ($module['questions'] as $questionIndex => $question)
                                    <div class="rounded-[18px] border border-[#e4e9ec] p-4" wire:key="{{ $question['key'] }}">
                                        <div class="mb-3 flex items-center justify-between gap-3"><p class="text-sm font-bold text-[#4f5960]">{{ __('Question :number', ['number' => $questionIndex + 1]) }}</p><flux:button wire:click="removeQuestion({{ $moduleIndex }}, {{ $questionIndex }})" wire:confirm="{{ __('ui.confirm_remove_question') }}" variant="ghost" size="sm" icon="trash" :aria-label="__('Remove question')" /></div>
                                        <div class="grid gap-3 sm:grid-cols-[1fr_180px_140px]"><flux:input wire:model.defer="modules.{{ $moduleIndex }}.questions.{{ $questionIndex }}.prompt" x-on:input="markDirty" :label="__('Question')" /><flux:select wire:model.live="modules.{{ $moduleIndex }}.questions.{{ $questionIndex }}.type" x-on:change="markDirty" :label="__('Question type')"><flux:select.option value="single_choice">{{ __('Single choice') }}</flux:select.option><flux:select.option value="multiple_choice">{{ __('Multiple choice') }}</flux:select.option></flux:select><flux:input type="number" wire:model.defer="modules.{{ $moduleIndex }}.questions.{{ $questionIndex }}.max_attempts" x-on:input="markDirty" :label="__('Attempts')" /></div>
                                        <fieldset class="mt-3 space-y-2"><legend class="mb-2 text-sm font-semibold">{{ __('Correct answer') }}</legend>
                                            @foreach ($question['options'] as $optionIndex => $option)
                                                <div class="flex items-center gap-2" wire:key="{{ $option['key'] }}">@if ($question['type'] === 'single_choice')<input type="radio" x-on:change="@foreach ($question['options'] as $candidateIndex => $candidate) $wire.set('modules.{{ $moduleIndex }}.questions.{{ $questionIndex }}.options.{{ $candidateIndex }}.is_correct', {{ $candidateIndex === $optionIndex ? 'true' : 'false' }}, false); @endforeach markDirty()" @checked($option['is_correct']) name="correct-{{ $question['key'] }}" aria-label="{{ __('Mark answer :number as correct', ['number' => $optionIndex + 1]) }}">@else<input type="checkbox" wire:model.defer="modules.{{ $moduleIndex }}.questions.{{ $questionIndex }}.options.{{ $optionIndex }}.is_correct" x-on:change="markDirty" aria-label="{{ __('Mark answer :number as correct', ['number' => $optionIndex + 1]) }}">@endif<flux:input wire:model.defer="modules.{{ $moduleIndex }}.questions.{{ $questionIndex }}.options.{{ $optionIndex }}.text" x-on:input="markDirty" :label="__('Answer :number', ['number' => $optionIndex + 1])" field:class="min-w-0 flex-1" /><flux:button wire:click="removeOption({{ $moduleIndex }}, {{ $questionIndex }}, {{ $optionIndex }})" variant="ghost" size="sm" icon="x-mark" :aria-label="__('Remove option')" /></div>
                                            @endforeach
                                        </fieldset>
                                        <flux:button wire:click="addOption({{ $moduleIndex }}, {{ $questionIndex }})" variant="ghost" size="sm" class="mt-3" icon="plus">{{ __('Add option') }}</flux:button>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-4 flex justify-end">
                                <flux:button wire:click="addQuestion({{ $moduleIndex }})" variant="ghost" icon="plus" class="w-full sm:w-auto">{{ __('Add question') }}</flux:button>
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
        <div class="flex justify-end">
            <flux:button wire:click="openNewModuleModal" variant="primary" icon="plus" class="w-full sm:w-auto" :aria-label="__('Create new shared module')">{{ __('Create new shared module') }}</flux:button>
        </div>
    </section>

    <section class="detail-card space-y-5"><h2 class="detail-card-title">{{ __('Publish') }}</h2><div class="grid gap-3 sm:grid-cols-2"><div class="metric-card metric-card--slate"><p class="metric-label">{{ __('Not started') }}</p><p class="metric-value">{{ $impact['not_started'] }}</p></div><div class="metric-card metric-card--amber"><p class="metric-label">{{ __('In progress') }}</p><p class="metric-value">{{ $impact['in_progress'] }}</p></div></div><flux:checkbox wire:model="restartInProgress" :label="__('Restart in-progress assignments')" /><flux:button wire:click="publish" wire:loading.attr="disabled" variant="primary" :disabled="$editorDirty || in_array(true, $assessmentDirty, true)">{{ __('Publish course and module changes') }}</flux:button></section>

    <x-editor-save-bar :dirty="$editorDirty" :dirty-module-count="count(array_filter($assessmentDirty))" :error="$saveError" :upload-in-progress="$uploadInProgress" course />

    <flux:modal wire:model.self="newModuleModalOpen" class="max-w-xl">
        <form wire:submit="createNewModule" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('New shared module') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Create a reusable draft and add it to this course.') }}</flux:text>
            </div>
            @if ($newModuleError)
                <flux:callout variant="danger" :heading="$newModuleError" />
            @endif
            <flux:input wire:model="newModuleForm.code" :label="__('Code')" required autofocus />
            <flux:input wire:model="newModuleForm.title" :label="__('Title')" required />
            <flux:textarea wire:model="newModuleForm.description" :label="__('Description')" rows="3" />
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <flux:button type="button" wire:click="$set('newModuleModalOpen', false)" variant="ghost" wire:loading.attr="disabled" wire:target="createNewModule">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="createNewModule">
                    <span wire:loading.remove wire:target="createNewModule">{{ __('Create and add module') }}</span>
                    <span wire:loading wire:target="createNewModule">{{ __('Creating module…') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="confirmingModuleRemoval" :dismissible="false" class="max-w-lg">
        <form wire:submit="removeModule" class="space-y-5">
            <div><flux:heading size="lg">{{ __('Remove module from this draft?') }}</flux:heading><flux:text class="mt-2">{{ __('The shared module, its questions, and its answers will not be deleted. Only its association with this draft will be removed.') }}</flux:text></div>
            @if ($moduleRemovalIndex !== null && isset($modules[$moduleRemovalIndex]))
                <div class="min-w-0 space-y-3 rounded-[18px] border border-[#dde3e7] bg-[#f7f9fa] p-4">
                    <div class="min-w-0">
                        <p class="break-words font-bold">{{ $modules[$moduleRemovalIndex]['code'] }} · {{ $modules[$moduleRemovalIndex]['title'] }}</p>
                        <p class="mt-1 break-words text-sm text-[#6f797f]">{{ __('Module version :number · :status', ['number' => $modules[$moduleRemovalIndex]['version_number'], 'status' => __(Str::headline($modules[$moduleRemovalIndex]['status']))]) }}</p>
                    </div>
                    <div class="min-w-0 border-t border-[#dde3e7] pt-3">
                        <p class="break-words font-semibold">{{ $courseForm['code'] }} · {{ $courseForm['title'] }}</p>
                        <p class="mt-1 break-words text-sm text-[#6f797f]">{{ __('Course draft version :number · :status', ['number' => $version->version_number, 'status' => __(Str::headline($version->status->value))]) }}</p>
                    </div>
                </div>
            @endif
            <flux:textarea wire:model="moduleRemovalReason" :label="__('Reason')" required />
            @error('removal') <flux:callout variant="danger" :heading="$message" /> @enderror
            <p wire:loading wire:target="removeModule" class="text-sm text-[#59656b]" role="status" aria-live="polite">{{ __('Removing module…') }}</p>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><flux:button class="w-full whitespace-normal sm:w-auto" type="button" wire:click="$set('confirmingModuleRemoval', false)" wire:loading.attr="disabled" wire:target="removeModule" variant="ghost">{{ __('Cancel') }}</flux:button><flux:button class="w-full whitespace-normal sm:w-auto" type="submit" wire:loading.attr="disabled" wire:target="removeModule" variant="danger"><span wire:loading.remove wire:target="removeModule">{{ __('Remove module') }}</span><span wire:loading wire:target="removeModule">{{ __('Removing module…') }}</span></flux:button></div>
        </form>
    </flux:modal>

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
            @php($videoUploadModuleIndex = (int) (explode('.', $videoEditorModel)[1] ?? 0))
            <div class="rounded-[18px] border border-dashed border-[#cfd8dd] bg-[#f7f9fa] p-4" x-data="lessonVideoUpload({{ $videoUploadModuleIndex }}, {{ Js::from(['fileTooLarge' => __('This video is larger than 200 MB. Select a smaller file.')]) }})">
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
