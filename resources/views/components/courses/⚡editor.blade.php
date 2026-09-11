<?php

use App\Actions\Courses\AddDirectCourseLesson;
use App\Actions\Courses\MutateCourseAssessmentStructure;
use App\Actions\Courses\PublishCourseVersion;
use App\Actions\Courses\RemoveDirectCourseLesson;
use App\Actions\Courses\ReorderDirectCourseContent;
use App\Actions\Courses\UpdateCourseEditorField;
use App\Actions\Courses\UpdateCourseModuleComposition;
use App\Actions\Videos\LinkExistingVideo;
use App\Actions\Videos\RequestVideoUpload;
use App\Actions\Videos\SyncVideoAsset;
use App\Contracts\VideoProvider;
use App\Enums\CourseVersionStatus;
use App\Enums\QuestionType;
use App\Enums\VideoStatus;
use App\Exceptions\CoursePublicationException;
use App\Exceptions\VideoProviderException;
use App\Models\ContentImage;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use App\Services\Courses\CoursePublicationImpact;
use App\Services\Courses\CourseVersionComposition;
use App\Services\Courses\CourseVersionValidator;
use App\Services\Courses\LessonContentRenderer;
use App\Services\Modules\EligibleModuleCatalog;
use App\Services\Video\VideoLibrary;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Single-screen course editor (docs/product-spec.md §14).
 *
 * Every edit writes straight to the draft — there is no giant nested payload to submit, so
 * the screen can never show "saved" state that never reached the database. Each mutation
 * re-authorizes against the draft and re-verifies that the row belongs to this version:
 * ids arriving from Livewire state are treated as untrusted input.
 */
new class extends Component
{
    use WithFileUploads;

    public Course $course;

    public CourseVersion $version;

    /** @var array<string, string|null> */
    public array $courseForm = [];

    /** @var array<string, string|null> */
    public array $versionForm = [];

    /** @var list<array<string, mixed>> */
    public array $lessons = [];

    /** @var list<int> */
    public array $expanded = [];

    public bool $confirmingPublish = false;

    public string $assignmentUpdateMode = 'keep_existing';

    public string $compositionMode = CourseVersionComposition::Empty;

    /** @var array{open: int, pending: int, in_progress: int} */
    public array $publicationImpact = ['open' => 0, 'pending' => 0, 'in_progress' => 0];

    public string $saveState = 'clean';

    /** Browser revision attached to the next persisted mutation. */
    public int $clientRevision = 0;

    /** @var list<array{property: string, revision: int}> Browser revisions awaiting acknowledgement by authored field path. */
    public array $clientFieldRevisions = [];

    /** Prevents publication while a browser edit has not received its matching save acknowledgement. */
    public bool $clientHasUnsavedChanges = false;

    /** Carries an unresolved browser request failure across unrelated successful mutations. */
    public bool $clientHasNetworkError = false;

    /** A module retry settles its own failed request without clearing an authored failure. */
    public bool $clientModuleNetworkError = false;

    /** Distinguishes a retryable module refusal from unrelated authored pending state. */
    public bool $moduleCompositionErrorPending = false;

    /** @var list<string> */
    public array $publishProblems = [];

    public ?string $savedAt = null;

    public bool $videoLibraryOpen = false;

    public ?int $videoLibraryLessonIndex = null;

    /** @var list<array<string, mixed>> */
    public array $videoLibraryItems = [];

    public ?string $videoLibraryError = null;

    public string $videoLibrarySearch = '';

    public ?string $videoLibraryPreviewUrl = null;

    public ?string $videoLibraryPreviewPoster = null;

    public ?string $videoLibraryPreviewTitle = null;

    public bool $imageLibraryOpen = false;

    public string $editorMediaModel = '';

    public $contentImageUpload;

    public string $moduleSearch = '';

    /** @var list<int> */
    public array $selectedModuleVersionIds = [];

    public function mount(Course $course): void
    {
        abort_if($course->is_shared, 404);
        $this->authorize('update', $course);

        $draft = $course->versions()->where('status', CourseVersionStatus::Draft->value)->first();

        abort_if($draft === null, 404);

        $this->course = $course;
        $this->version = $draft;
        $this->courseForm = [
            'code' => $course->code,
            'title' => $course->title,
            'description' => $course->description,
        ];
        $this->versionForm = [
            'title' => $draft->title,
            'description' => $draft->description,
        ];

        $this->loadLessons();
        $this->loadComposition();
        $this->expanded = collect($this->lessons)->pluck('id')->take(1)->all();
    }

    /**
     * Autosave dispatcher. Field paths come from the browser, so each branch resolves the
     * row through a guarded lookup rather than trusting the id in the path.
     */
    public function updated(string $property, mixed $value): void
    {
        if (! $this->isPersistedProperty($property)) {
            return;
        }

        $this->authorize('updateVersion', $this->version);
        $this->saveState = 'saving';

        try {
            match (true) {
                str_starts_with($property, 'courseForm.') => $this->saveCourseField(substr($property, 11), $value),
                str_starts_with($property, 'versionForm.') => $this->saveVersionField(substr($property, 12), $value),
                (bool) preg_match('/^lessons\.(\d+)\.questions\.(\d+)\.options\.(\d+)\.(\w+)$/', $property, $m) => $this->saveOptionField((int) $m[1], (int) $m[2], (int) $m[3], $m[4], $value),
                (bool) preg_match('/^lessons\.(\d+)\.questions\.(\d+)\.(\w+)$/', $property, $m) => $this->saveQuestionField((int) $m[1], (int) $m[2], $m[3], $value),
                (bool) preg_match('/^lessons\.(\d+)\.(\w+)$/', $property, $m) => $this->saveLessonField((int) $m[1], $m[2], $value),
                default => null,
            };
        } catch (ValidationException $exception) {
            $this->saveState = 'validation-error';
            throw $exception;
        }
    }

    public function toggleLesson(int $lessonId): void
    {
        $this->expanded = in_array($lessonId, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$lessonId]))
            : [...$this->expanded, $lessonId];
    }

    public function addModule(int $moduleVersionId, UpdateCourseModuleComposition $action): void
    {
        if (! in_array($moduleVersionId, $this->selectedModuleVersionIds, true)) {
            $this->selectedModuleVersionIds[] = $moduleVersionId;
        }

        $this->persistModuleComposition($action);
    }

    public function removeModule(int $index, UpdateCourseModuleComposition $action): void
    {
        abort_unless(array_key_exists($index, $this->selectedModuleVersionIds), 404);
        array_splice($this->selectedModuleVersionIds, $index, 1);
        $this->persistModuleComposition($action);
    }

    public function moveModule(int $index, int $direction, UpdateCourseModuleComposition $action): void
    {
        $target = $index + $direction;
        abort_unless(array_key_exists($index, $this->selectedModuleVersionIds), 404);

        if ($target < 0 || $target >= count($this->selectedModuleVersionIds)) {
            return;
        }

        [$this->selectedModuleVersionIds[$index], $this->selectedModuleVersionIds[$target]] = [$this->selectedModuleVersionIds[$target], $this->selectedModuleVersionIds[$index]];
        $this->persistModuleComposition($action);
    }

    public function addLesson(AddDirectCourseLesson $action): void
    {
        $lesson = $action->handle($this->version, auth()->user());

        $this->loadLessons();
        $this->loadComposition();
        $this->expanded = [...$this->expanded, $lesson->id];
        $this->touchSaved();
    }

    public function removeLesson(int $index, RemoveDirectCourseLesson $action): void
    {
        $action->handle($this->version, auth()->user(), $this->lessonAt($index)->id);
        $this->loadLessons();
        $this->loadComposition();
        $this->touchSaved();
    }

    public function moveLesson(int $index, int $direction, ReorderDirectCourseContent $action): void
    {
        $lessons = $this->version->lessons()->orderBy('position')->get();
        $target = $index + $direction;

        if ($target < 0 || $target >= $lessons->count()) {
            return;
        }

        $ids = $lessons->pluck('id')->all();
        $movedLessonId = (int) $ids[$index];
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        $this->performReorder(fn () => $action->handle($this->version, auth()->user(), 'lessons', null, $ids));

        $this->loadLessons();
        $this->touchSaved();
        $this->dispatch('lesson-reordered', lessonId: $movedLessonId, direction: $direction < 0 ? 'up' : 'down');
    }

    public function addQuestion(int $lessonIndex, MutateCourseAssessmentStructure $action): void
    {
        $lesson = $this->lessonAt($lessonIndex);
        $action->handle($this->version, auth()->user(), 'add_question', $lesson->id);

        $this->loadLessons();
        $this->touchSaved();
    }

    public function removeQuestion(int $lessonIndex, int $questionIndex, MutateCourseAssessmentStructure $action): void
    {
        $question = $this->questionAt($lessonIndex, $questionIndex);
        $action->handle($this->version, auth()->user(), 'remove_question', $question->lesson_id, $question->id);
        $this->loadLessons();
        $this->touchSaved();
    }

    public function addOption(int $lessonIndex, int $questionIndex, MutateCourseAssessmentStructure $action): void
    {
        $question = $this->questionAt($lessonIndex, $questionIndex);

        $action->handle($this->version, auth()->user(), 'add_option', $question->id);

        $this->loadLessons();
        $this->touchSaved();
    }

    public function removeOption(int $lessonIndex, int $questionIndex, int $optionIndex, MutateCourseAssessmentStructure $action): void
    {
        $question = $this->questionAt($lessonIndex, $questionIndex);
        $action->handle($this->version, auth()->user(), 'remove_option', $question->id, $this->optionAt($lessonIndex, $questionIndex, $optionIndex)->id);
        $this->loadLessons();
        $this->touchSaved();
    }

    /** Single choice keeps exactly one correct option, so selecting one clears the others. */
    public function selectSingleCorrect(int $lessonIndex, int $questionIndex, int $optionIndex, MutateCourseAssessmentStructure $action): void
    {
        $question = $this->questionAt($lessonIndex, $questionIndex);
        $option = $this->optionAt($lessonIndex, $questionIndex, $optionIndex);

        $action->handle($this->version, auth()->user(), 'select_single_correct', $question->id, $option->id);

        foreach ($this->lessons[$lessonIndex]['questions'][$questionIndex]['options'] as $index => $current) {
            $this->lessons[$lessonIndex]['questions'][$questionIndex]['options'][$index]['is_correct'] = $index === $optionIndex;
        }
        $this->touchSaved();
    }

    public function moveQuestion(int $lessonIndex, int $questionIndex, int $direction, ReorderDirectCourseContent $action): void
    {
        $lesson = $this->lessonAt($lessonIndex);
        $ids = $lesson->questions()->pluck('id')->all();
        $target = $questionIndex + $direction;
        if ($target < 0 || $target >= count($ids)) {
            return;
        }
        [$ids[$questionIndex], $ids[$target]] = [$ids[$target], $ids[$questionIndex]];
        $this->performReorder(fn () => $action->handle($this->version, auth()->user(), 'questions', $lesson->id, $ids));
        $this->loadLessons();
        $this->touchSaved();
    }

    public function moveOption(int $lessonIndex, int $questionIndex, int $optionIndex, int $direction, ReorderDirectCourseContent $action): void
    {
        $question = $this->questionAt($lessonIndex, $questionIndex);
        $ids = $question->options()->pluck('id')->all();
        $target = $optionIndex + $direction;
        if ($target < 0 || $target >= count($ids)) {
            return;
        }
        [$ids[$optionIndex], $ids[$target]] = [$ids[$target], $ids[$optionIndex]];
        $this->performReorder(fn () => $action->handle($this->version, auth()->user(), 'options', $question->id, $ids));
        $this->loadLessons();
        $this->touchSaved();
    }

    /** @param list<int> $orderedIds */
    public function reorderLessons(array $orderedIds, ReorderDirectCourseContent $action): void
    {
        $this->performReorder(fn () => $action->handle($this->version, auth()->user(), 'lessons', null, $orderedIds));
        $this->loadLessons();
        $this->touchSaved();
    }

    /** @param list<int> $orderedIds */
    public function reorderQuestions(int $lessonIndex, array $orderedIds, ReorderDirectCourseContent $action): void
    {
        $lessonId = $this->lessonAt($lessonIndex)->id;
        $this->performReorder(fn () => $action->handle($this->version, auth()->user(), 'questions', $lessonId, $orderedIds));
        $this->loadLessons();
        $this->touchSaved();
    }

    /** @param list<int> $orderedIds */
    public function reorderOptions(int $lessonIndex, int $questionIndex, array $orderedIds, ReorderDirectCourseContent $action): void
    {
        $questionId = $this->questionAt($lessonIndex, $questionIndex)->id;
        $this->performReorder(fn () => $action->handle($this->version, auth()->user(), 'options', $questionId, $orderedIds));
        $this->loadLessons();
        $this->touchSaved();
    }

    /** Opens a one-time upload slot at the provider and hands the URL to the browser. */
    public function requestUpload(int $lessonIndex, RequestVideoUpload $action): string
    {
        $upload = $action->handle($this->lessonAt($lessonIndex));

        $this->refreshLessonVideo($lessonIndex);

        return $upload->uploadUrl;
    }

    public function uploadCompleted(int $lessonIndex, VideoLibrary $library): void
    {
        $lesson = $this->lessonAt($lessonIndex);

        $lesson->video?->update(['status' => VideoStatus::Processing]);

        $this->refreshLessonVideo($lessonIndex);
        $this->touchSaved();

        if ($this->videoLibraryOpen && $this->videoLibraryLessonIndex === $lessonIndex) {
            $this->videoLibrarySearch = '';
            $this->searchVideoLibrary($library);
        }
    }

    public function openVideoLibrary(int $lessonIndex, VideoLibrary $library): void
    {
        $this->lessonAt($lessonIndex);
        $this->editorMediaModel = '';
        $this->videoLibraryLessonIndex = $lessonIndex;
        $this->videoLibraryError = null;
        $this->videoLibrarySearch = '';
        $this->clearVideoLibraryPreview();
        $this->videoLibraryOpen = true;

        try {
            $this->videoLibraryItems = $library->items();
        } catch (VideoProviderException) {
            $this->videoLibraryItems = [];
            $this->videoLibraryError = __('The video library could not be loaded. Try again.');
        }
    }

    public function openEditorVideoLibrary(string $model, VideoLibrary $library): void
    {
        abort_unless(preg_match('/^lessons\.(\d+)\.content_markdown$/', $model, $matches) === 1, 422);
        $this->editorMediaModel = $model;
        $this->openVideoLibrary((int) $matches[1], $library);
        $this->editorMediaModel = $model;
    }

    public function searchVideoLibrary(VideoLibrary $library): void
    {
        abort_if($this->videoLibraryLessonIndex === null, 404);

        $this->videoLibraryError = null;
        $this->clearVideoLibraryPreview();

        try {
            $this->videoLibraryItems = $library->items($this->videoLibrarySearch);
        } catch (VideoProviderException) {
            $this->videoLibraryItems = [];
            $this->videoLibraryError = __('The video library could not be loaded. Try again.');
        }
    }

    public function previewLibraryVideo(string $assetId): void
    {
        $item = collect($this->videoLibraryItems)
            ->first(fn (array $item): bool => hash_equals((string) $item['asset_id'], $assetId));
        abort_if($item === null || $item['status'] !== VideoStatus::Ready->value, 404);

        $this->videoLibraryPreviewUrl = $item['preview_url'];
        $this->videoLibraryPreviewPoster = $item['thumbnail_url'];
        $this->videoLibraryPreviewTitle = $item['title'];
    }

    public function linkExistingVideo(string $assetId, LinkExistingVideo $action): void
    {
        abort_if($this->videoLibraryLessonIndex === null, 404);

        $listed = collect($this->videoLibraryItems)
            ->first(fn (array $item): bool => hash_equals((string) $item['asset_id'], $assetId));
        abort_if($listed === null, 404);

        $action->handle($this->lessonAt($this->videoLibraryLessonIndex), $assetId);

        if ($this->editorMediaModel !== '') {
            $this->dispatch('oceanix:insert-video',
                model: $this->editorMediaModel,
                previewUrl: $listed['preview_url'],
                posterUrl: $listed['thumbnail_url'],
                title: $listed['title'],
                aspectRatio: $listed['aspect_ratio'],
            );
        }

        $this->refreshLessonVideo($this->videoLibraryLessonIndex);
        $this->videoLibraryOpen = false;
        $this->videoLibraryLessonIndex = null;
        $this->videoLibraryItems = [];
        $this->touchSaved();
    }

    public function openImageLibrary(string $model): void
    {
        abort_unless(preg_match('/^lessons\.(\d+)\.content_markdown$/', $model, $matches) === 1, 422);
        $this->lessonAt((int) $matches[1]);
        $this->editorMediaModel = $model;
        $this->imageLibraryOpen = true;
        $this->resetValidation('contentImageUpload');
    }

    public function uploadContentImage(): void
    {
        $this->authorize('updateVersion', $this->version);
        $this->validate(['contentImageUpload' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240']]);

        $upload = $this->contentImageUpload;
        $disk = (string) config('filesystems.content_images_disk', 'public');
        $path = $upload->store((string) config('filesystems.content_images_path', 'content-images'), $disk);
        abort_if($path === false, 500);

        $image = ContentImage::query()->create([
            'company_id' => $this->course->company_id,
            'is_shared' => false,
            'name' => $upload->getClientOriginalName(),
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $upload->getSize(),
        ]);

        $this->reset('contentImageUpload');
        $this->selectContentImage($image->id);
    }

    public function selectContentImage(int $imageId): void
    {
        abort_unless($this->editorMediaModel !== '', 422);
        $image = ContentImage::query()
            ->where('company_id', $this->course->company_id)
            ->where('is_shared', false)
            ->findOrFail($imageId);

        $this->dispatch('oceanix:insert-image',
            model: $this->editorMediaModel,
            url: $image->url(),
            alt: pathinfo($image->name, PATHINFO_FILENAME),
        );
        $this->imageLibraryOpen = false;
    }

    private function clearVideoLibraryPreview(): void
    {
        $this->videoLibraryPreviewUrl = null;
        $this->videoLibraryPreviewPoster = null;
        $this->videoLibraryPreviewTitle = null;
    }

    /** Polled while any video is still encoding. */
    public function syncVideos(SyncVideoAsset $action): void
    {
        $this->authorize('updateVersion', $this->version);

        $pending = $this->version->lessons()
            ->with('video')
            ->get()
            ->pluck('video')
            ->filter(fn (?Video $video): bool => $video !== null
                && $video->status !== VideoStatus::Ready
                && $video->status !== VideoStatus::Uploading);

        foreach ($pending as $video) {
            $action->handle($video);
        }

        foreach (array_keys($this->lessons) as $lessonIndex) {
            $this->refreshLessonVideo($lessonIndex);
        }
    }

    public function confirmPublish(CourseVersionValidator $validator, CoursePublicationImpact $impact): void
    {
        $this->authorize('publish', $this->course);
        $this->ensureEditorIsSaved();

        $this->publishProblems = $validator->problems($this->version);
        $this->publicationImpact = $impact->forVersion($this->version);
        $this->assignmentUpdateMode = 'keep_existing';
        $this->confirmingPublish = true;
    }

    public function publish(PublishCourseVersion $action): void
    {
        $this->authorize('publish', $this->course);
        $this->ensureEditorIsSaved();

        try {
            $action->handle(
                $this->version,
                auth()->id(),
                $this->assignmentUpdateMode === 'replace_open',
            );
        } catch (CoursePublicationException $e) {
            $this->publishProblems = $e->problems;

            return;
        }

        session()->flash('status', __('ui.version_published', ['number' => $this->version->version_number]));

        $this->redirect(route('courses.show', ['course' => $this->course]), navigate: true);
    }

    public function with(CourseVersionValidator $validator, VideoProvider $videoProvider, EligibleModuleCatalog $moduleCatalog, CourseVersionComposition $composition): array
    {
        $moduleGroups = $moduleCatalog->forCourseEditor($this->course->company, auth()->user(), $this->moduleSearch);
        $state = $composition->inspect($this->version);

        return [
            'problems' => $validator->problems($this->version),
            'openAssignmentCount' => $this->publicationImpact['open'],
            'usingFakeVideoProvider' => $videoProvider->key() === 'local_fake',
            'hasEncodingVideo' => collect($this->lessons)->contains(
                fn (array $lesson): bool => in_array($lesson['video']['status'] ?? null, ['uploading', 'processing'], true)
            ),
            'moduleGroups' => $moduleGroups,
            'selectedModules' => $state['reusableRows'],
            'contentImages' => ContentImage::query()
                ->where('company_id', $this->course->company_id)
                ->where('is_shared', false)
                ->latest()
                ->limit(60)
                ->get(),
        ];
    }

    // ---------------------------------------------------------------- persistence

    private function saveCourseField(string $field, mixed $value): void
    {
        if (! in_array($field, ['title', 'description'], true)) {
            return;
        }
        $this->validateOnly("courseForm.{$field}", [
            'courseForm.title' => ['required', 'string', 'max:200'],
            'courseForm.description' => ['nullable', 'string', 'max:2000'],
        ]);

        $saved = app(UpdateCourseEditorField::class)->handle($this->version, auth()->user(), 'course', $this->course->id, $field, $value);
        $this->course->{$field} = $saved;
        if ($field === 'title') {
            $this->versionForm['title'] = $saved;
        }

        $this->touchSaved(acknowledgeAuthoredEdit: true, property: "courseForm.{$field}");
    }

    private function saveVersionField(string $field, mixed $value): void
    {
        if (! in_array($field, ['title', 'description'], true)) {
            return;
        }
        $this->validateOnly("versionForm.{$field}", [
            'versionForm.title' => ['required', 'string', 'max:200'],
            'versionForm.description' => ['nullable', 'string', 'max:2000'],
        ]);

        app(UpdateCourseEditorField::class)->handle($this->version, auth()->user(), 'version', $this->version->id, $field, $value);
        $this->touchSaved(acknowledgeAuthoredEdit: true, property: "versionForm.{$field}");
    }

    private function saveLessonField(int $index, string $field, mixed $value): void
    {
        $allowed = ['title', 'description', 'content_markdown', 'is_required', 'minimum_watch_percentage', 'passing_score'];

        if (! in_array($field, $allowed, true)) {
            return;
        }
        $this->validateOnly("lessons.{$index}.{$field}", [
            "lessons.{$index}.title" => ['required', 'string', 'max:200'],
            "lessons.{$index}.description" => ['nullable', 'string', 'max:2000'],
            "lessons.{$index}.content_markdown" => ['nullable', 'string', 'max:100000'],
            "lessons.{$index}.is_required" => ['boolean'],
            "lessons.{$index}.minimum_watch_percentage" => ['required', 'integer', 'min:1', 'max:100'],
            "lessons.{$index}.passing_score" => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $value = app(UpdateCourseEditorField::class)->handle($this->version, auth()->user(), 'lesson', $this->lessonAt($index)->id, $field, $value);
        $this->lessons[$index][$field] = $value;
        $this->touchSaved(acknowledgeAuthoredEdit: true, property: "lessons.{$index}.{$field}");
    }

    private function saveQuestionField(int $lessonIndex, int $questionIndex, string $field, mixed $value): void
    {
        if (! in_array($field, ['prompt', 'type', 'max_attempts'], true)) {
            return;
        }
        $this->validateOnly("lessons.{$lessonIndex}.questions.{$questionIndex}.{$field}", [
            "lessons.{$lessonIndex}.questions.{$questionIndex}.prompt" => ['required', 'string', 'max:1000'],
            "lessons.{$lessonIndex}.questions.{$questionIndex}.type" => ['required', 'in:single_choice,multiple_choice'],
            "lessons.{$lessonIndex}.questions.{$questionIndex}.max_attempts" => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $question = $this->questionAt($lessonIndex, $questionIndex);
        app(UpdateCourseEditorField::class)->handle($this->version, auth()->user(), 'question', $question->id, $field, $value);

        // Narrowing to single choice must leave at most one correct answer behind.
        if ($field === 'type' && $value === QuestionType::SingleChoice->value) {
            $keep = $question->options()->where('is_correct', true)->orderBy('position')->first();
            $keptOptionId = $keep?->id;

            foreach ($this->lessons[$lessonIndex]['questions'][$questionIndex]['options'] as $index => $option) {
                $this->lessons[$lessonIndex]['questions'][$questionIndex]['options'][$index]['is_correct'] = $option['id'] === $keptOptionId;
            }
        }

        $this->touchSaved(acknowledgeAuthoredEdit: true, property: "lessons.{$lessonIndex}.questions.{$questionIndex}.{$field}");
    }

    private function saveOptionField(int $lessonIndex, int $questionIndex, int $optionIndex, string $field, mixed $value): void
    {
        if (! in_array($field, ['text', 'is_correct'], true)) {
            return;
        }
        $this->validateOnly("lessons.{$lessonIndex}.questions.{$questionIndex}.options.{$optionIndex}.{$field}", [
            "lessons.{$lessonIndex}.questions.{$questionIndex}.options.{$optionIndex}.text" => ['nullable', 'string', 'max:1000'],
            "lessons.{$lessonIndex}.questions.{$questionIndex}.options.{$optionIndex}.is_correct" => ['boolean'],
        ]);

        app(UpdateCourseEditorField::class)->handle($this->version, auth()->user(), 'option', $this->optionAt($lessonIndex, $questionIndex, $optionIndex)->id, $field, $value);
        $this->touchSaved(acknowledgeAuthoredEdit: true, property: "lessons.{$lessonIndex}.questions.{$questionIndex}.options.{$optionIndex}.{$field}");
    }

    // ---------------------------------------------------------------- guarded lookups

    private function lessonAt(int $index): Lesson
    {
        $this->authorize('updateVersion', $this->version);

        $id = $this->lessons[$index]['id'] ?? null;
        $lesson = Lesson::query()
            ->where('course_version_id', $this->version->id)
            ->find($id);

        abort_if($lesson === null, 404);

        return $lesson;
    }

    private function questionAt(int $lessonIndex, int $questionIndex): Question
    {
        $lesson = $this->lessonAt($lessonIndex);
        $id = $this->lessons[$lessonIndex]['questions'][$questionIndex]['id'] ?? null;
        $question = $lesson->questions()->find($id);

        abort_if($question === null, 404);

        return $question;
    }

    private function optionAt(int $lessonIndex, int $questionIndex, int $optionIndex): QuestionOption
    {
        $question = $this->questionAt($lessonIndex, $questionIndex);
        $id = $this->lessons[$lessonIndex]['questions'][$questionIndex]['options'][$optionIndex]['id'] ?? null;
        $option = $question->options()->find($id);

        abort_if($option === null, 404);

        return $option;
    }

    private function loadLessons(): void
    {
        $this->version->refresh();

        $renderer = app(LessonContentRenderer::class);
        $this->lessons = $this->version->lessons()->with(['video', 'questions.options'])->get()
            ->map(fn (Lesson $lesson): array => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'content_markdown' => $renderer->editorContent((string) $lesson->content_markdown),
                'position' => $lesson->position,
                'is_required' => $lesson->is_required,
                'minimum_watch_percentage' => $lesson->minimum_watch_percentage,
                'passing_score' => $lesson->passing_score,
                'video' => $this->videoState($lesson->video),
                'questions' => $lesson->questions->map(fn (Question $question): array => [
                    'id' => $question->id,
                    'prompt' => $question->prompt,
                    'type' => $question->type->value,
                    'max_attempts' => $question->max_attempts,
                    'options' => $question->options->map(fn (QuestionOption $option): array => [
                        'id' => $option->id,
                        'text' => $option->text,
                        'is_correct' => $option->is_correct,
                    ])->all(),
                ])->all(),
            ])->all();
    }

    private function refreshLessonVideo(int $lessonIndex): void
    {
        $lesson = $this->lessonAt($lessonIndex);
        $lesson->load('video');
        $this->lessons[$lessonIndex]['video'] = $this->videoState($lesson->video);
    }

    /** @return array<string, mixed>|null */
    private function videoState(?Video $video): ?array
    {
        if ($video === null) {
            return null;
        }

        return [
            'status' => $video->status->value,
            'status_label' => $video->status->label(),
            'pill' => $video->status->pillModifier(),
            'duration' => $video->formattedDuration(),
            'preview' => rescue(fn (): ?array => app(VideoLibrary::class)->preview($video), null, report: false),
        ];
    }

    private function touchSaved(bool $acknowledgeAuthoredEdit = false, ?string $property = null): void
    {
        $this->savedAt = now()->format('H:i:s');

        if ($acknowledgeAuthoredEdit && $property !== null) {
            $this->clientHasNetworkError = false;
            $revisionIndex = collect($this->clientFieldRevisions)->search(
                fn (mixed $pending): bool => is_array($pending) && ($pending['property'] ?? null) === $property
            );
            $revision = $revisionIndex === false
                ? $this->clientRevision
                : (int) $this->clientFieldRevisions[$revisionIndex]['revision'];
            if ($revisionIndex !== false) {
                array_splice($this->clientFieldRevisions, $revisionIndex, 1);
            }

            $hasAuthoredValidationErrors = collect($this->getErrorBag()->keys())
                ->contains(fn (string $key): bool => $this->isPersistedProperty($key));
            $this->clientHasUnsavedChanges = $this->clientModuleNetworkError || $this->moduleCompositionErrorPending || $this->clientFieldRevisions !== [] || $hasAuthoredValidationErrors;
            $this->saveState = $hasAuthoredValidationErrors
                ? 'validation-error'
                : ($this->clientModuleNetworkError ? 'network-error' : ($this->moduleCompositionErrorPending ? 'validation-error' : ($this->clientHasUnsavedChanges ? 'dirty' : 'saved')));
            $this->dispatch('editor-field-saved', at: $this->savedAt, property: $property, revision: $revision);

            return;
        }

        if ($this->clientModuleNetworkError) {
            $this->clientHasUnsavedChanges = true;
            $this->saveState = $this->moduleCompositionErrorPending ? 'validation-error' : 'network-error';
        }

        if ($this->clientHasUnsavedChanges && ! $acknowledgeAuthoredEdit) {
            if (! in_array($this->saveState, ['validation-error', 'network-error'], true)) {
                $this->saveState = 'dirty';
            }
            $this->dispatch('editor-structure-saved', at: $this->savedAt);

            return;
        }

        $this->saveState = 'saved';
        $this->dispatch('editor-saved', at: $this->savedAt, revision: $this->clientRevision);
    }

    private function performReorder(callable $reorder): void
    {
        try {
            $reorder();
        } catch (ValidationException $exception) {
            $this->saveState = 'validation-error';
            $this->clientHasUnsavedChanges = true;
            $this->dispatch('editor-validation-error');

            throw $exception;
        }
    }

    private function ensureEditorIsSaved(): void
    {
        if ($this->clientHasUnsavedChanges || in_array($this->saveState, ['dirty', 'saving', 'validation-error', 'network-error'], true)) {
            throw ValidationException::withMessages(['publish' => __('ui.finish_saving_before_publish')]);
        }
    }

    private function isPersistedProperty(string $property): bool
    {
        return preg_match('/^(courseForm\.(title|description)|versionForm\.(title|description)|lessons\.\d+\.(title|description|content_markdown|is_required|minimum_watch_percentage|passing_score)|lessons\.\d+\.questions\.\d+\.(prompt|type|max_attempts)|lessons\.\d+\.questions\.\d+\.options\.\d+\.(text|is_correct))$/', $property) === 1;
    }

    private function persistModuleComposition(UpdateCourseModuleComposition $action): void
    {
        try {
            $action->handle($this->version, $this->selectedModuleVersionIds, auth()->user());
        } catch (ValidationException $exception) {
            $this->loadComposition();
            $this->saveState = 'validation-error';
            $this->clientHasUnsavedChanges = true;
            $this->moduleCompositionErrorPending = true;
            $this->dispatch('editor-validation-error');
            throw $exception;
        } catch (LogicException $exception) {
            if ($exception->getMessage() !== __('One or more selected modules are unavailable.')) {
                throw $exception;
            }

            $this->loadComposition();
            $this->saveState = 'validation-error';
            $this->clientHasUnsavedChanges = true;
            $this->moduleCompositionErrorPending = true;
            $this->dispatch('editor-validation-error');

            throw ValidationException::withMessages(['modules' => __('One or more selected modules are unavailable.')]);
        }
        $this->loadComposition();
        $recoveringFromModuleError = $this->moduleCompositionErrorPending || $this->clientModuleNetworkError;
        $this->clientModuleNetworkError = false;
        $this->moduleCompositionErrorPending = false;
        $this->resetErrorBag('modules');
        if ($recoveringFromModuleError) {
            $hasAuthoredValidationErrors = collect($this->getErrorBag()->keys())
                ->contains(fn (string $key): bool => $this->isPersistedProperty($key));
            $this->clientHasUnsavedChanges = $this->clientHasNetworkError || $this->clientFieldRevisions !== [] || $hasAuthoredValidationErrors;
            $this->saveState = $hasAuthoredValidationErrors
                ? 'validation-error'
                : ($this->clientHasNetworkError ? 'network-error' : ($this->clientHasUnsavedChanges ? 'dirty' : 'saving'));
        }
        $this->touchSaved();
    }

    private function loadComposition(): void
    {
        $state = app(CourseVersionComposition::class)->inspect($this->version);
        $this->compositionMode = $state['mode'];
        $this->selectedModuleVersionIds = $state['reusableRows']->pluck('lesson_id')->map(fn ($id) => (int) $id)->all();
    }
};
?>

<div
    class="admin-page min-w-0 space-y-7"
    x-data="{ state: @js($saveState), dirty: false, revision: @js($clientRevision), savingRevision: null, structuralPending: false, fieldRevisions: @js($clientFieldRevisions), unscopedDirty: false, networkError: @js($clientHasNetworkError), drag: null, observedValues: {}, modulePending: false, stopActionTracking: null, moveIds(ids, from, to) { const next = [...ids]; const old = next.indexOf(from); const target = next.indexOf(to); if (old < 0 || target < 0 || old === target) return next; next.splice(target, 0, next.splice(old, 1)[0]); return next; }, trackFieldRevision(property, revision) { this.fieldRevisions = [...this.fieldRevisions.filter((pending) => pending.property !== property), { property, revision }]; $wire.$set('clientFieldRevisions', [...this.fieldRevisions], false); }, markDirty(event) { const control = event.target.closest('[data-editor-authored]'); const binding = control?.getAttributeNames().find(name => name.startsWith('wire:model')); const property = binding ? control.getAttribute(binding) : null; const checkable = control?.type === 'checkbox' || control?.type === 'radio' || control?.localName === 'ui-checkbox'; const value = JSON.stringify(checkable ? control.checked : control?.value ?? event.detail); const identity = [control.closest('[data-lesson-drop-id]')?.dataset?.lessonDropId, control.closest('[data-editor-question-id]')?.dataset?.editorQuestionId, control.closest('[data-editor-option-id]')?.dataset?.editorOptionId, property?.startsWith('lessons.') ? property.split('.').at(-1) : property].join(':'); const previous = this.observedValues[identity] ?? JSON.stringify(checkable ? Boolean($wire.$get(property)) : String($wire.$get(property) ?? '')); if (previous === value) return; this.observedValues[identity] = value; this.revision++; this.dirty = true; this.state = 'dirty'; if (property) { this.trackFieldRevision(property, this.revision); } else { this.unscopedDirty = true; } $wire.$set('clientRevision', this.revision, false); $wire.$set('clientHasUnsavedChanges', true, false); }, restoreLessonFocus(lessonId, direction) { this.$nextTick(() => requestAnimationFrame(() => { const preferred = document.querySelector(`[data-lesson-focus-id='${lessonId}'][data-lesson-focus-direction='${direction}']:not([disabled])`); const fallback = document.querySelector(`[data-lesson-focus-id='${lessonId}']:not([disabled])`); (preferred || fallback)?.focus(); })); }, finishLessonPointer(event, ids) { const active = this.drag; this.drag = null; if (active?.type !== 'lesson') return; const element = document.elementFromPoint(event.clientX, event.clientY); const target = element?.closest('[data-lesson-drop-id]'); const targetId = Number(target?.dataset.lessonDropId); const targetParent = Number(target?.dataset.lessonDropParent); if (!Number.isInteger(targetId) || targetParent !== active.parent || targetId === active.id) return; event.preventDefault(); this.structuralPending = true; this.state = 'saving'; $wire.reorderLessons(this.moveIds(ids, active.id, targetId)); }, destroy() { this.stopActionTracking?.(); }, init() { this.stopActionTracking = $wire.$interceptAction(({ action }) => { if (!['addModule', 'moveModule', 'removeModule', 'addLesson', 'removeLesson', 'moveLesson', 'reorderLessons', 'addQuestion', 'removeQuestion', 'moveQuestion', 'reorderQuestions', 'addOption', 'removeOption', 'moveOption', 'reorderOptions', 'selectSingleCorrect'].includes(action.name)) return; this.modulePending = ['addModule', 'moveModule', 'removeModule'].includes(action.name); this.structuralPending = true; this.state = $wire.clientModuleNetworkError && !this.modulePending ? 'network-error' : 'saving'; }); $wire.$hook('request', ({ succeed, fail }) => { if (!this.dirty && !this.structuralPending) return; const moduleRequest = this.modulePending; this.modulePending = false; const requestRevision = this.revision; this.savingRevision = requestRevision; this.state = $wire.clientModuleNetworkError && !moduleRequest ? 'network-error' : 'saving'; succeed(() => setTimeout(() => { if (this.revision !== requestRevision) return; if ($wire.saveState === 'validation-error') { this.dirty = true; this.structuralPending = false; this.state = 'validation-error'; } else if ($wire.clientModuleNetworkError) { this.dirty = true; this.state = 'network-error'; } })); fail(() => { this.dirty = true; this.structuralPending = false; this.networkError = true; this.state = 'network-error'; $wire.$set('clientHasUnsavedChanges', true, false); if (moduleRequest) { $wire.$set('clientModuleNetworkError', true, false); } else { $wire.$set('clientHasNetworkError', true, false); } }); }); } }"
    x-on:input.capture="if ($event.isTrusted && $event.target.closest('[data-editor-authored]')) markDirty($event)"
    x-on:change.capture="if ($event.isTrusted && $event.target.closest('[data-editor-authored]')) markDirty($event)"
    x-on:pointerup.window="finishLessonPointer($event, {{ Js::from(array_column($lessons, 'id')) }})"
    x-on:pointercancel.window="drag = null"
    x-on:editor-validation-error.window="structuralPending = false; dirty = true; state = 'validation-error'"
    x-on:editor-structure-saved.window="structuralPending = false; state = dirty ? (['validation-error', 'network-error'].includes($wire.saveState) ? $wire.saveState : 'dirty') : 'saved'"
    x-on:editor-field-saved.window="const index = fieldRevisions.findIndex((pending) => pending.property === $event.detail.property && pending.revision === $event.detail.revision); if (index !== -1) { fieldRevisions.splice(index, 1); networkError = $wire.clientModuleNetworkError; $wire.$set('clientHasNetworkError', false, false); dirty = unscopedDirty || fieldRevisions.length > 0 || $wire.clientHasUnsavedChanges; state = $wire.saveState }"
    x-on:editor-saved.window="structuralPending = false; if ($wire.clientModuleNetworkError) { dirty = true; networkError = true; state = 'network-error' } else if ($event.detail.revision === revision) { dirty = false; networkError = false; state = 'saved' }"
    x-on:lesson-reordered.window="restoreLessonFocus($event.detail.lessonId, $event.detail.direction)"
    x-on:livewire:navigate.window="if (dirty && ! window.confirm({{ Js::from(__('ui.leave_unsaved_confirm')) }})) $event.preventDefault()"
    x-on:oceanix-open-image-library.window="$wire.openImageLibrary($event.detail.model)"
    x-on:oceanix-open-video-library.window="$wire.openEditorVideoLibrary($event.detail.model)"
    @if ($hasEncodingVideo) wire:poll.5s="syncVideos" @endif>
    <x-page-hero
        :kicker="__('ui.draft_version', ['number' => $version->version_number])"
        :title="__('ui.course_editor')"
        :description="__('ui.course_editor_description')">
        <span class="text-xs font-semibold text-[#8a9298]" aria-live="polite">
            <span x-show="state === 'clean'">{{ __('ui.editor_clean') }}</span>
            <span x-show="state === 'dirty'">{{ __('ui.editor_unsaved') }}</span>
            <span x-show="state === 'saving'">{{ __('ui.editor_saving') }}</span>
            <span x-show="state === 'saved'">{{ $savedAt ? __('ui.saved_at', ['time' => $savedAt]) : __('ui.editor_saved') }}</span>
            <span x-show="state === 'validation-error'" class="text-red-600">{{ __('ui.editor_validation_error') }}</span>
            <span x-show="state === 'network-error'" class="text-red-600">{{ __('ui.editor_network_error') }}</span>
        </span>
        <flux:button :href="route('courses.show', ['course' => $course])" wire:navigate variant="ghost" size="sm">{{ __('ui.back_to_course') }}</flux:button>
        @can('publish', $course)
            <flux:button wire:click="confirmPublish" wire:loading.attr="disabled" wire:target="confirmPublish,publish" variant="primary" class="admin-primary-action" x-bind:disabled="dirty || state === 'saving' || $wire.compositionMode === 'mixed'" :disabled="$compositionMode === App\Services\Courses\CourseVersionComposition::Mixed">{{ __('Publish version') }}</flux:button>
        @endcan
    </x-page-hero>

    @error('publish')
        <flux:callout variant="danger" :heading="$message" />
    @enderror
    @if($version && $version->isEditable())
        <x-courses.preview-link-panel :$course :$version />
    @endif

    @if ($usingFakeVideoProvider)
        <flux:callout variant="secondary" :heading="__('ui.fake_video_provider')" :text="__('ui.fake_video_provider_help')" />
    @endif

    @if ($problems !== [])
        <flux:callout variant="warning" :heading="__('ui.not_publishable_yet')">
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </flux:callout>
    @endif

    <p class="text-sm font-semibold text-[#5f6a71]" role="status">{{ __('ui.composition_mode', ['mode' => __('ui.composition_'.$compositionMode)]) }}</p>
    @if ($compositionMode === App\Services\Courses\CourseVersionComposition::Mixed)
        <flux:callout variant="danger" :heading="__('ui.mixed_composition_title')" :text="__('ui.mixed_composition_recovery')" />
    @endif

    {{-- Course details --}}
    <section class="form-panel rounded-[20px] border border-[#dde3e7] p-5 sm:p-6">
        <h2 class="text-base font-bold text-[#262d33]">{{ __('Course details') }}</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-[160px_minmax(0,1fr)]">
            <flux:input :value="$courseForm['code']" readonly class="admin-control" :label="__('Code')" :description="__('ui.permanent_course_code')" />
            <div>
                <flux:input wire:model.live.debounce.500ms="courseForm.title" data-editor-authored class="admin-control" :label="__('Title')" />
                <flux:error name="courseForm.title" />
            </div>
        </div>
        <flux:textarea wire:model.live.blur="courseForm.description" data-editor-authored class="admin-control mt-4" :label="__('Description')" rows="2" />
    </section>

    {{-- Version settings --}}
    <section class="form-panel rounded-[20px] border border-[#dde3e7] p-5 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-bold text-[#262d33]">{{ __('Version settings') }}</h2>
            <span class="status-pill status-pill--neutral">{{ __('Draft') }}</span>
        </div>
        <div class="mt-4">
            <flux:textarea wire:model.live.blur="versionForm.description" data-editor-authored class="admin-control" :label="__('Description shown to the employee')" rows="3" />
            <p class="mt-2 text-xs text-[#8a9298]">{{ __('ui.version_description_note') }}</p>
        </div>
        <p class="mt-4 border-t border-[#eef1f4] pt-4 text-xs text-[#8a9298]">{{ __('ui.completion_rule_note') }}</p>
    </section>

    {{-- Immutable module composition --}}
    <section class="detail-card space-y-5" @if ($errors->first('modules') === __('One or more selected modules are unavailable.')) aria-describedby="module-unavailable-recovery" @endif>
        <div>
            <p class="admin-kicker">{{ __('Reusable content') }}</p>
            <h2 class="mt-1 text-xl font-bold tracking-tight text-[#242a2f]">{{ __('Course Modules') }}</h2>
            <p class="mt-1 text-sm text-[#707a80]">{{ __('Compose this draft from published Company Modules and Shared Modules.') }}</p>
        </div>
        @error('modules')
            @if ($message === __('One or more selected modules are unavailable.'))
                <flux:callout id="module-unavailable-recovery" variant="danger" :heading="$message" :text="__('ui.module_unavailable_recovery')" />
            @else
                <flux:callout variant="danger" :heading="$message" />
            @endif
        @enderror
        @if ($compositionMode === App\Services\Courses\CourseVersionComposition::DirectLessons)
            <p id="module-composition-conflict" class="text-sm font-medium text-[#5f6a71]">{{ __('ui.module_composition_conflict') }}</p>
        @elseif ($compositionMode === App\Services\Courses\CourseVersionComposition::Mixed)
            <p id="mixed-module-composition-conflict" class="text-sm font-medium text-[#5f6a71]">{{ __('ui.mixed_composition_recovery') }}</p>
        @endif
        <div class="space-y-2" aria-live="polite">
            @forelse ($selectedModules as $index => $composition)
                <div class="flex items-center gap-3 rounded-[16px] border border-[#e4e9ec] bg-[#f8fafb] p-3" wire:key="composition-{{ $composition->id }}">
                    <span class="grid size-8 place-items-center rounded-xl bg-[#e4f0f5] text-sm font-bold text-[#1c6b84]">{{ $index + 1 }}</span>
                    <div class="min-w-0 flex-1"><p class="truncate font-semibold">{{ $composition->moduleVersion->title }}</p><p class="text-xs text-[#7d878d]">{{ $composition->moduleVersion->is_shared ? __('Shared Module') : __('Company Module') }} · {{ __('Version :number', ['number' => $composition->moduleVersion->version_number]) }}</p></div>
                    <flux:button data-editor-mutation wire:click="moveModule({{ $index }}, -1)" wire:loading.attr="disabled" wire:target="moveModule" variant="ghost" size="sm" icon="chevron-up" :aria-label="__('Move module up')" :disabled="$index === 0" />
                    <flux:button data-editor-mutation wire:click="moveModule({{ $index }}, 1)" wire:loading.attr="disabled" wire:target="moveModule" variant="ghost" size="sm" icon="chevron-down" :aria-label="__('Move module down')" :disabled="$index === $selectedModules->count() - 1" />
                    <flux:button data-editor-mutation wire:click="removeModule({{ $index }})" wire:loading.attr="disabled" wire:target="removeModule" variant="ghost" size="sm" icon="trash" :aria-label="__('Remove module')" />
                </div>
            @empty
                <x-empty-state :title="__('No modules selected')" :description="__('Choose a published module below. Legacy lessons remain available during migration.')" />
            @endforelse
        </div>
        <flux:input wire:model.live.debounce.300ms="moduleSearch" data-editor-ignore-dirty type="search" :label="__('Search modules')" :placeholder="__('Search by title or code')" />
        <div wire:loading.delay wire:target="moduleSearch" role="status" class="text-sm text-[#5f6a71]">{{ __('Searching modules…') }}</div>
        <div class="grid gap-5 md:grid-cols-2" wire:loading.remove.delay wire:target="moduleSearch">
            @foreach (['company' => __('Company Modules'), 'shared' => __('Shared Modules')] as $group => $label)
                <div><h3 class="text-sm font-bold">{{ $label }}</h3><div class="mt-3 space-y-2">
                    @forelse ($moduleGroups[$group] as $module)
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-[16px] border border-[#e4e9ec] p-3" wire:key="picker-{{ $group }}-{{ $module->id }}"><div><p class="font-semibold">{{ $module->title }}</p><p class="text-xs text-[#7d878d]">{{ $module->code }} · {{ $group === 'shared' ? __('Managed by platform') : __('Managed by company') }}</p></div><flux:button data-editor-mutation wire:click="addModule({{ $module->id }})" wire:loading.attr="disabled" wire:target="addModule" variant="ghost" size="sm" :disabled="in_array($module->id, $selectedModuleVersionIds, true) || in_array($compositionMode, [App\Services\Courses\CourseVersionComposition::DirectLessons, App\Services\Courses\CourseVersionComposition::Mixed], true)" :aria-describedby="match ($compositionMode) { App\Services\Courses\CourseVersionComposition::DirectLessons => 'module-composition-conflict', App\Services\Courses\CourseVersionComposition::Mixed => 'mixed-module-composition-conflict', default => null }">{{ __('Add module') }}</flux:button></div>
                    @empty
                        <p class="rounded-[16px] border border-dashed border-[#d8e0e4] p-4 text-sm text-[#707a80]">{{ __('No eligible modules found.') }}</p>
                    @endforelse
                </div></div>
            @endforeach
        </div>
    </section>

    {{-- Lessons --}}
    <section class="space-y-4">
        <div class="flex items-end justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.14em] text-[#8a9298]">{{ __('ui.content') }}</p>
                <h2 class="mt-1 text-xl font-bold tracking-tight text-[#242a2f]">{{ __('Lessons') }}</h2>
            </div>
            <flux:button data-editor-mutation wire:click="addLesson" wire:loading.attr="disabled" wire:target="addLesson" variant="ghost" size="sm" icon="plus" :disabled="in_array($compositionMode, [App\Services\Courses\CourseVersionComposition::Modules, App\Services\Courses\CourseVersionComposition::Mixed], true)" :aria-describedby="match ($compositionMode) { App\Services\Courses\CourseVersionComposition::Modules => 'direct-lesson-conflict', App\Services\Courses\CourseVersionComposition::Mixed => 'mixed-direct-lesson-conflict', default => null }">{{ __('Add lesson') }}</flux:button>
        </div>

        @if ($compositionMode === App\Services\Courses\CourseVersionComposition::Modules)
            <p id="direct-lesson-conflict" class="text-sm font-medium text-[#5f6a71]">{{ __('ui.direct_lesson_conflict') }}</p>
        @elseif ($compositionMode === App\Services\Courses\CourseVersionComposition::Mixed)
            <p id="mixed-direct-lesson-conflict" class="text-sm font-medium text-[#5f6a71]">{{ __('ui.mixed_composition_recovery') }}</p>
        @endif

        @error('order')
            <flux:callout variant="danger" :heading="$message" />
        @enderror

        @forelse ($lessons as $lessonIndex => $lesson)
            <div class="min-w-0 rounded-[22px] border border-[#dde3e7] bg-white shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]" wire:key="lesson-{{ $lesson['id'] }}" data-lesson-drop-id="{{ $lesson['id'] }}" data-lesson-drop-parent="{{ $version->id }}">
                <div class="flex flex-wrap items-center gap-3 p-4 sm:p-5">
                    <button type="button" x-on:pointerdown.stop.prevent="drag = { type: 'lesson', parent: {{ $version->id }}, id: {{ $lesson['id'] }} }" class="grid size-9 shrink-0 cursor-grab touch-none place-items-center rounded-xl bg-[#e4f0f5] text-sm font-bold text-[#1c6b84] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#3e8ba3]" aria-label="{{ __('ui.drag_lesson', ['position' => $lesson['position']]) }}">{{ $lesson['position'] }}</button>

                    <button type="button" wire:click="toggleLesson({{ $lesson['id'] }})" class="min-w-0 flex-1 text-left">
                        <span class="block truncate font-bold text-[#262d33]">{{ $lesson['title'] ?: __('Untitled lesson') }}</span>
                        <span class="mt-0.5 block text-xs text-[#8a9298]">
                            {{ trans_choice('ui.questions_count', count($lesson['questions']), ['count' => count($lesson['questions'])]) }}
                        </span>
                        @if ($lesson['video'])<span class="mt-1 block text-xs font-semibold text-[#5f6a71]">{{ __('ui.video_status', ['status' => $lesson['video']['status_label']]) }}</span>@endif
                    </button>

                    <div class="flex items-center gap-1">
                        <flux:button data-editor-mutation data-lesson-focus-id="{{ $lesson['id'] }}" data-lesson-focus-direction="up" wire:click="moveLesson({{ $lessonIndex }}, -1)" wire:loading.attr="disabled" wire:target="moveLesson,reorderLessons" variant="ghost" size="sm" icon="chevron-up" :aria-label="__('ui.move_lesson_up', ['position' => $lesson['position']])" :disabled="$lessonIndex === 0" />
                        <flux:button data-editor-mutation data-lesson-focus-id="{{ $lesson['id'] }}" data-lesson-focus-direction="down" wire:click="moveLesson({{ $lessonIndex }}, 1)" wire:loading.attr="disabled" wire:target="moveLesson,reorderLessons" variant="ghost" size="sm" icon="chevron-down" :aria-label="__('ui.move_lesson_down', ['position' => $lesson['position']])" :disabled="$lessonIndex === count($lessons) - 1" />
                        <flux:button data-editor-mutation wire:click="removeLesson({{ $lessonIndex }})" wire:loading.attr="disabled" wire:target="removeLesson" wire:confirm="{{ __('ui.confirm_remove_lesson') }}" variant="ghost" size="sm" icon="trash" :aria-label="__('ui.remove_lesson', ['position' => $lesson['position']])" />
                        <flux:button wire:click="toggleLesson({{ $lesson['id'] }})" variant="ghost" size="sm" :icon="in_array($lesson['id'], $expanded, true) ? 'chevron-double-up' : 'chevron-double-down'" :aria-label="in_array($lesson['id'], $expanded, true) ? __('ui.collapse_lesson', ['position' => $lesson['position']]) : __('ui.expand_lesson', ['position' => $lesson['position']])" />
                    </div>
                </div>

                @if (in_array($lesson['id'], $expanded, true))
                    <div class="border-t border-[#eef1f4] p-4 sm:p-5" wire:key="lesson-fields-{{ $lesson['id'] }}-{{ $lessonIndex }}">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <flux:input wire:model.live.blur="lessons.{{ $lessonIndex }}.title" data-editor-authored class="admin-control" :label="__('Lesson title')" />
                            <div>
                                <flux:field>
                                    <x-field-label :hint="__('ui.passing_score_help')">{{ __('Passing score (%)') }}</x-field-label>
                                    <flux:input type="number" min="1" max="100" wire:model.live.blur="lessons.{{ $lessonIndex }}.passing_score" data-editor-authored class="admin-control" />
                                    <flux:error name="lessons.{{ $lessonIndex }}.passing_score" />
                                </flux:field>
                            </div>
                        </div>
                        <div class="mt-4 max-w-xs">
                            <flux:field>
                                <x-field-label :hint="__('ui.watch_threshold_help')">{{ __('Watch threshold (%)') }}</x-field-label>
                                <flux:input type="number" min="1" max="100" wire:model.live.blur="lessons.{{ $lessonIndex }}.minimum_watch_percentage" data-editor-authored class="admin-control" />
                                <flux:error name="lessons.{{ $lessonIndex }}.minimum_watch_percentage" />
                            </flux:field>
                        </div>
                        <flux:textarea wire:model.live.blur="lessons.{{ $lessonIndex }}.description" data-editor-authored class="admin-control mt-4" :label="__('Lesson description')" rows="2" />
                        <flux:checkbox wire:model.live="lessons.{{ $lessonIndex }}.is_required" data-editor-authored class="mt-4" :label="__('Required to complete the course')" />

                        <div class="mt-5">
                            <flux:editor
                                wire:model.live.debounce.500ms="lessons.{{ $lessonIndex }}.content_markdown"
                                data-editor-authored
                                data-oceanix-editor-model="lessons.{{ $lessonIndex }}.content_markdown"
                                data-oceanix-video-preview-url="{{ data_get($lesson, 'video.preview.preview_url') }}"
                                data-oceanix-video-poster-url="{{ data_get($lesson, 'video.preview.poster_url') }}"
                                data-oceanix-video-title="{{ $lesson['title'] }}"
                                data-oceanix-video-aspect-ratio="{{ data_get($lesson, 'video.preview.aspect_ratio', '16/9') }}"
                                class="oceanix-content-editor"
                                :label="__('Lesson content')"
                                :description="__('Format the lesson visually and insert images or videos where they should appear.')"
                                toolbar="heading | bold italic underline strike | bullet ordered blockquote link | align | image image-left image-center image-right image-size video ~ fullscreen undo redo" />
                            <flux:error name="lessons.{{ $lessonIndex }}.content_markdown" />
                        </div>

                        {{-- Questions --}}
                        <div class="mt-5">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-bold text-[#262d33]">{{ __('Assessment') }}</p>
                                <flux:button data-editor-mutation wire:click="addQuestion({{ $lessonIndex }})" wire:loading.attr="disabled" wire:target="addQuestion" variant="ghost" size="sm" icon="plus">{{ __('Add question') }}</flux:button>
                            </div>

                            <div class="mt-3 space-y-3">
                                @forelse ($lesson['questions'] as $questionIndex => $question)
                                    <div class="min-w-0 rounded-[18px] border border-[#e4e9ec] p-4" data-editor-question-id="{{ $question['id'] }}" wire:key="question-{{ $question['id'] }}-{{ $lessonIndex }}-{{ $questionIndex }}" x-on:dragover.stop.prevent x-on:drop.stop.prevent="if (drag?.type === 'question' && drag.parent === {{ $lesson['id'] }}) { structuralPending = true; state = 'saving'; $wire.reorderQuestions({{ $lessonIndex }}, moveIds({{ Js::from(array_column($lesson['questions'], 'id')) }}, drag.id, {{ $question['id'] }})); } drag = null">
                                        <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_180px_140px_auto] lg:items-end">
                                            <div class="flex min-w-0 items-end gap-2">
                                                <button type="button" draggable="true" x-on:dragstart.stop="drag = { type: 'question', parent: {{ $lesson['id'] }}, id: {{ $question['id'] }} }" class="mb-1 cursor-grab rounded-lg p-2 text-[#5f6a71] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#3e8ba3]" aria-label="{{ __('ui.drag_question', ['position' => $questionIndex + 1]) }}"><flux:icon.bars-3 class="size-4" /></button>
                                                <flux:input wire:model.live.blur="lessons.{{ $lessonIndex }}.questions.{{ $questionIndex }}.prompt" data-editor-authored class="admin-control min-w-0 flex-1" :label="__('ui.question_position', ['position' => $questionIndex + 1])" />
                                            </div>
                                            <flux:select wire:model.live="lessons.{{ $lessonIndex }}.questions.{{ $questionIndex }}.type" data-editor-authored class="admin-control" :label="__('Type')">
                                                @foreach (App\Enums\QuestionType::cases() as $case)
                                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                                @endforeach
                                            </flux:select>
                                            <flux:field>
                                                <x-field-label :hint="__('ui.attempts_help')">{{ __('Attempts') }}</x-field-label>
                                                <flux:input type="number" min="1" max="10" wire:model.live.blur="lessons.{{ $lessonIndex }}.questions.{{ $questionIndex }}.max_attempts" data-editor-authored class="admin-control" />
                                                <flux:error name="lessons.{{ $lessonIndex }}.questions.{{ $questionIndex }}.max_attempts" />
                                            </flux:field>
                                            <div class="flex flex-wrap gap-1">
                                                <flux:button data-editor-mutation wire:click="moveQuestion({{ $lessonIndex }}, {{ $questionIndex }}, -1)" wire:loading.attr="disabled" wire:target="moveQuestion,reorderQuestions" variant="ghost" size="sm" icon="chevron-up" :aria-label="__('ui.move_question_up', ['position' => $questionIndex + 1])" :disabled="$questionIndex === 0" />
                                                <flux:button data-editor-mutation wire:click="moveQuestion({{ $lessonIndex }}, {{ $questionIndex }}, 1)" wire:loading.attr="disabled" wire:target="moveQuestion,reorderQuestions" variant="ghost" size="sm" icon="chevron-down" :aria-label="__('ui.move_question_down', ['position' => $questionIndex + 1])" :disabled="$questionIndex === count($lesson['questions']) - 1" />
                                                <flux:button data-editor-mutation wire:click="removeQuestion({{ $lessonIndex }}, {{ $questionIndex }})" wire:loading.attr="disabled" wire:target="removeQuestion" wire:confirm="{{ __('ui.confirm_remove_question') }}" variant="ghost" size="sm" icon="trash" :aria-label="__('Remove question')" />
                                            </div>
                                        </div>

                                        <div class="mt-4 space-y-2">
                                            @foreach ($question['options'] as $optionIndex => $option)
                                                <div class="flex min-w-0 flex-wrap items-end gap-2 sm:flex-nowrap" data-editor-option-id="{{ $option['id'] }}" wire:key="option-{{ $option['id'] }}-{{ $lessonIndex }}-{{ $questionIndex }}-{{ $optionIndex }}" x-on:dragover.stop.prevent x-on:drop.stop.prevent="if (drag?.type === 'option' && drag.parent === {{ $question['id'] }}) { structuralPending = true; state = 'saving'; $wire.reorderOptions({{ $lessonIndex }}, {{ $questionIndex }}, moveIds({{ Js::from(array_column($question['options'], 'id')) }}, drag.id, {{ $option['id'] }})); } drag = null">
                                                    <button type="button" draggable="true" x-on:dragstart.stop="drag = { type: 'option', parent: {{ $question['id'] }}, id: {{ $option['id'] }} }" class="cursor-grab rounded-lg p-2 text-[#5f6a71] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#3e8ba3]" aria-label="{{ __('ui.drag_answer', ['position' => $optionIndex + 1]) }}"><flux:icon.bars-3 class="size-4" /></button>
                                                    @if ($question['type'] === App\Enums\QuestionType::SingleChoice->value)
                                                        <input type="radio"
                                                            data-editor-mutation data-editor-ignore-dirty wire:click="selectSingleCorrect({{ $lessonIndex }}, {{ $questionIndex }}, {{ $optionIndex }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="selectSingleCorrect"
                                                            @checked($option['is_correct'])
                                                            name="correct-{{ $question['id'] }}"
                                                            class="size-4 border-[#8e989f] text-[#1c6b84] focus:ring-[#3e8ba3]"
                                                            aria-label="{{ __('ui.correct_answer_position', ['position' => $optionIndex + 1]) }}">
                                                    @else
                                                        <input type="checkbox"
                                                            wire:model.live="lessons.{{ $lessonIndex }}.questions.{{ $questionIndex }}.options.{{ $optionIndex }}.is_correct"
                                                            data-editor-authored
                                                            class="size-4 rounded border-[#8e989f] text-[#1c6b84] focus:ring-[#3e8ba3]"
                                                            aria-label="{{ __('ui.correct_answer_position', ['position' => $optionIndex + 1]) }}">
                                                    @endif
                                                    <flux:input wire:model.live.debounce.500ms="lessons.{{ $lessonIndex }}.questions.{{ $questionIndex }}.options.{{ $optionIndex }}.text" data-editor-authored class="admin-control min-w-0 flex-1 basis-48" :label="__('ui.answer_position', ['position' => $optionIndex + 1])" />
                                                    <flux:button data-editor-mutation wire:click="moveOption({{ $lessonIndex }}, {{ $questionIndex }}, {{ $optionIndex }}, -1)" wire:loading.attr="disabled" wire:target="moveOption,reorderOptions" variant="ghost" size="sm" icon="chevron-up" :aria-label="__('ui.move_answer_up', ['position' => $optionIndex + 1])" :disabled="$optionIndex === 0" />
                                                    <flux:button data-editor-mutation wire:click="moveOption({{ $lessonIndex }}, {{ $questionIndex }}, {{ $optionIndex }}, 1)" wire:loading.attr="disabled" wire:target="moveOption,reorderOptions" variant="ghost" size="sm" icon="chevron-down" :aria-label="__('ui.move_answer_down', ['position' => $optionIndex + 1])" :disabled="$optionIndex === count($question['options']) - 1" />
                                                    <flux:button data-editor-mutation wire:click="removeOption({{ $lessonIndex }}, {{ $questionIndex }}, {{ $optionIndex }})" wire:loading.attr="disabled" wire:target="removeOption" variant="ghost" size="sm" icon="x-mark" :aria-label="__('Remove option')" />
                                                </div>
                                            @endforeach
                                        </div>

                                        <flux:button data-editor-mutation wire:click="addOption({{ $lessonIndex }}, {{ $questionIndex }})" wire:loading.attr="disabled" wire:target="addOption" variant="ghost" size="sm" class="mt-3" icon="plus">{{ __('Add option') }}</flux:button>
                                    </div>
                                @empty
                                    <x-empty-state
                                        icon="question-mark-circle"
                                        :title="__('ui.no_questions')"
                                        :description="__('ui.no_questions_help')" />
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <x-empty-state
                icon="film"
                :title="__('ui.no_lessons_draft')"
                :description="__('ui.no_lessons_draft_help')">
                <flux:button data-editor-mutation wire:click="addLesson" wire:loading.attr="disabled" wire:target="addLesson" variant="primary" class="admin-primary-action" :disabled="in_array($compositionMode, [App\Services\Courses\CourseVersionComposition::Modules, App\Services\Courses\CourseVersionComposition::Mixed], true)" :aria-describedby="match ($compositionMode) { App\Services\Courses\CourseVersionComposition::Modules => 'direct-lesson-conflict', App\Services\Courses\CourseVersionComposition::Mixed => 'mixed-direct-lesson-conflict', default => null }">{{ __('Add lesson') }}</flux:button>
            </x-empty-state>
        @endforelse
    </section>

    <flux:modal wire:model.self="imageLibraryOpen" class="max-w-4xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Image library') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Upload an image or reuse one owned by this company.') }}</flux:text>
            </div>
            <form wire:submit="uploadContentImage" class="rounded-[18px] border border-dashed border-[#cfd8dd] bg-[#f7f9fa] p-5">
                <label class="block text-sm font-bold text-[#262d33]">{{ __('Upload from computer') }}
                    <input wire:model="contentImageUpload" data-editor-ignore-dirty type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="mt-3 block w-full rounded-xl border border-[#cfd8dd] bg-white p-3 text-sm">
                </label>
                <p class="mt-2 text-xs text-[#7d878e]">{{ __('JPG, PNG, WebP or GIF, up to 10 MB.') }}</p>
                @error('contentImageUpload') <p class="mt-2 text-sm font-medium text-red-600">{{ $message }}</p> @enderror
                <flux:button type="submit" wire:loading.attr="disabled" wire:target="contentImageUpload,uploadContentImage" variant="primary" class="mt-4">{{ __('Upload and insert image') }}</flux:button>
            </form>
            <div>
                <h3 class="text-sm font-bold text-[#262d33]">{{ __('Company gallery') }}</h3>
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

    <flux:modal wire:model.self="videoLibraryOpen" class="max-w-3xl">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Video library') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Select a ready video from Cloudflare Stream to reuse in this lesson.') }}</flux:text>
            </div>

            @if ($videoLibraryLessonIndex !== null)
                <div
                    class="rounded-[18px] border border-dashed border-[#cfd8dd] bg-[#f7f9fa] p-4"
                    x-data="lessonVideoUpload({{ $videoLibraryLessonIndex }}, {{ Js::from(['fileTooLarge' => __('This video is larger than 200 MB. Select a smaller file.')]) }})">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm font-bold text-[#293238]">{{ __('Upload a new video') }}</p>
                            <p class="mt-1 text-xs text-[#707a80]">{{ __('The file is uploaded directly and will appear in this library while it is processing.') }}</p>
                        </div>
                        <div>
                            <input type="file" accept="video/*" class="hidden" x-ref="file" x-on:change="start($event)">
                            <flux:button variant="primary" icon="arrow-up-tray" x-on:click="$refs.file.click()" ::disabled="uploading">
                                <span x-show="! uploading">{{ __('Choose video') }}</span>
                                <span x-show="uploading" x-text="`${progress}%`"></span>
                            </flux:button>
                        </div>
                    </div>
                    <p class="mt-3 text-sm font-medium text-red-600" x-show="error" x-text="error"></p>
                </div>
            @endif

            <div class="flex gap-2">
                <flux:input
                    wire:model="videoLibrarySearch"
                    data-editor-ignore-dirty
                    wire:keydown.enter="searchVideoLibrary"
                    class="admin-control flex-1"
                    icon="magnifying-glass"
                    :label="__('Search videos')"
                    :placeholder="__('Search by video name')" />
                <flux:button
                    wire:click="searchVideoLibrary"
                    wire:loading.attr="disabled"
                    wire:target="searchVideoLibrary"
                    variant="ghost"
                    class="self-end">
                    {{ __('Search') }}
                </flux:button>
            </div>

            @if ($videoLibraryPreviewUrl)
                <section class="overflow-hidden rounded-[20px] border border-[#dde3e7] bg-[#11181c]" wire:key="video-library-preview-{{ md5($videoLibraryPreviewUrl) }}">
                    <video
                        x-data="videoLibraryPreview({{ Js::from($videoLibraryPreviewUrl) }}, {{ Js::from($videoLibraryPreviewPoster) }})"
                        x-ref="video"
                        class="aspect-video w-full bg-black"
                        controls
                        playsinline></video>
                    <p class="px-4 py-3 text-sm font-bold text-white">{{ $videoLibraryPreviewTitle }}</p>
                </section>
            @endif

            @if ($videoLibraryError)
                <flux:callout variant="danger" :heading="$videoLibraryError" />
            @elseif ($videoLibraryItems === [])
                <x-empty-state
                    icon="film"
                    :title="__('No videos found')"
                    :description="__('Upload a video to Cloudflare Stream and it will appear here.')" />
            @else
                <div class="grid max-h-[55vh] gap-3 overflow-y-auto pr-1 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($videoLibraryItems as $item)
                        <article class="overflow-hidden rounded-2xl border border-[#dde3e7] bg-white" wire:key="library-video-{{ $item['asset_id'] }}">
                            <button
                                type="button"
                                wire:click="previewLibraryVideo('{{ $item['asset_id'] }}')"
                                class="group relative block aspect-video w-full overflow-hidden bg-[#e8eef1] text-left disabled:cursor-not-allowed"
                                aria-label="{{ __('Preview :video', ['video' => $item['title']]) }}"
                                @disabled($item['status'] !== App\Enums\VideoStatus::Ready->value || $item['preview_url'] === null)>
                                @if ($item['thumbnail_url'])
                                    <img src="{{ $item['thumbnail_url'] }}" alt="" class="size-full object-cover transition group-hover:scale-[1.02]">
                                @else
                                    <span class="grid size-full place-items-center text-[#8a9298]">
                                        <flux:icon.film class="size-8" />
                                    </span>
                                @endif
                                @if ($item['status'] === App\Enums\VideoStatus::Ready->value && $item['preview_url'])
                                    <span class="absolute inset-0 grid place-items-center bg-black/10 opacity-0 transition group-hover:opacity-100">
                                        <span class="grid size-11 place-items-center rounded-full bg-white/95 text-[#1c6b84] shadow">
                                            <flux:icon.play class="size-5" />
                                        </span>
                                    </span>
                                @endif
                            </button>
                            <div class="p-3">
                                <p class="truncate text-sm font-bold text-[#262d33]">{{ $item['title'] }}</p>
                                <p class="mt-1 text-xs text-[#8a9298]">{{ $item['duration'] }} · {{ $item['created_at'] }}</p>
                                <div class="mt-3 flex items-center justify-between gap-2">
                                    <span class="status-pill {{ $item['pill'] }}">{{ $item['status_label'] }}</span>
                                    <flux:button
                                        data-editor-mutation
                                        wire:click="linkExistingVideo('{{ $item['asset_id'] }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="linkExistingVideo"
                                        variant="ghost"
                                        size="sm"
                                        :disabled="$item['status'] !== App\Enums\VideoStatus::Ready->value">
                                        {{ __('Use video') }}
                                    </flux:button>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            @error('videoLibrary')
                <flux:callout variant="danger" :heading="$message" />
            @enderror

            <div class="flex justify-end">
                <flux:button x-on:click="$flux.modal.close()" variant="ghost">{{ __('Close') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Publish confirmation --}}
    <flux:modal wire:model.self="confirmingPublish" class="max-w-lg">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('ui.publish_confirm_title', ['number' => $version->version_number]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('ui.publish_confirm_body') }}</flux:text>
            </div>

            @error('publish')
                <flux:callout variant="danger" :heading="$message" />
            @enderror

            @if ($publishProblems !== [])
                <flux:callout variant="danger" :heading="__('ui.not_publishable_yet')">
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                        @foreach ($publishProblems as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                </flux:callout>
            @endif

            @if ($openAssignmentCount > 0)
                <fieldset class="space-y-3 rounded-[16px] border border-[#dde3e7] p-4">
                    <legend class="px-1 text-sm font-bold text-[#262d33]">{{ trans_choice('ui.existing_assignments_title', $openAssignmentCount, ['count' => $openAssignmentCount]) }}</legend>
                    <p class="text-xs text-[#707a80]">{{ __('ui.assignment_impact', ['pending' => $publicationImpact['pending'], 'in_progress' => $publicationImpact['in_progress']]) }}</p>
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="radio" wire:model="assignmentUpdateMode" data-editor-ignore-dirty value="keep_existing" class="mt-1 size-4 border-[#8e989f] text-[#1c6b84] focus:ring-[#3e8ba3]">
                        <span><strong class="block text-sm text-[#262d33]">{{ __('ui.keep_existing_assignments') }}</strong><span class="text-xs text-[#707a80]">{{ __('ui.keep_existing_assignments_help') }}</span></span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="radio" wire:model="assignmentUpdateMode" data-editor-ignore-dirty value="replace_open" class="mt-1 size-4 border-[#8e989f] text-[#1c6b84] focus:ring-[#3e8ba3]">
                        <span><strong class="block text-sm text-[#262d33]">{{ __('ui.replace_open_assignments') }}</strong><span class="text-xs text-[#707a80]">{{ __('ui.replace_open_assignments_help') }}</span></span>
                    </label>
                </fieldset>
            @endif

            <div class="flex flex-wrap justify-end gap-2">
                <flux:button x-on:click="$wire.confirmingPublish = false" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="publish" wire:loading.attr="disabled" wire:target="publish" variant="primary" class="admin-primary-action" :disabled="count($publishProblems) > 0">
                    <span wire:loading.remove wire:target="publish">{{ __('Publish version') }}</span>
                    <span wire:loading wire:target="publish" role="status">{{ __('ui.publishing_version') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
