<?php

use App\Actions\Courses\PublishCourseVersion;
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
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\UserTrainingAssignment;
use App\Models\Video;
use App\Services\Courses\CourseVersionValidator;
use App\Services\Modules\EligibleModuleCatalog;
use App\Services\Video\VideoLibrary;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

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

    public string $assignmentUpdateMode = 'replace_open';

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

    public string $moduleSearch = '';

    /** @var list<int> */
    public array $selectedModuleVersionIds = [];

    public function mount(Course $course): void
    {
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
        $this->selectedModuleVersionIds = $draft->moduleCompositions()->pluck('lesson_id')->map(fn ($id) => (int) $id)->all();
        $this->expanded = collect($this->lessons)->pluck('id')->take(1)->all();
    }

    /**
     * Autosave dispatcher. Field paths come from the browser, so each branch resolves the
     * row through a guarded lookup rather than trusting the id in the path.
     */
    public function updated(string $property, mixed $value): void
    {
        $this->authorize('updateVersion', $this->version);

        match (true) {
            str_starts_with($property, 'courseForm.') => $this->saveCourseField(substr($property, 11), $value),
            str_starts_with($property, 'versionForm.') => $this->saveVersionField(substr($property, 12), $value),
            (bool) preg_match('/^lessons\.(\d+)\.questions\.(\d+)\.options\.(\d+)\.(\w+)$/', $property, $m) => $this->saveOptionField((int) $m[1], (int) $m[2], (int) $m[3], $m[4], $value),
            (bool) preg_match('/^lessons\.(\d+)\.questions\.(\d+)\.(\w+)$/', $property, $m) => $this->saveQuestionField((int) $m[1], (int) $m[2], $m[3], $value),
            (bool) preg_match('/^lessons\.(\d+)\.(\w+)$/', $property, $m) => $this->saveLessonField((int) $m[1], $m[2], $value),
            default => null,
        };
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

    public function addLesson(): void
    {
        $this->authorize('updateVersion', $this->version);

        $lesson = Lesson::query()->create([
            'course_version_id' => $this->version->id,
            'title' => __('New lesson'),
            'position' => ((int) $this->version->lessons()->max('position')) + 1,
        ]);

        $this->loadLessons();
        $this->expanded = [...$this->expanded, $lesson->id];
        $this->touchSaved();
    }

    public function removeLesson(int $index): void
    {
        $this->lessonAt($index)->delete();
        $this->resequenceLessons();
        $this->loadLessons();
        $this->touchSaved();
    }

    public function moveLesson(int $index, int $direction): void
    {
        $lessons = $this->version->lessons()->orderBy('position')->get();
        $target = $index + $direction;

        if ($target < 0 || $target >= $lessons->count()) {
            return;
        }

        $this->authorize('updateVersion', $this->version);

        DB::transaction(function () use ($lessons, $index, $target): void {
            $lessons[$index]->update(['position' => $target + 1]);
            $lessons[$target]->update(['position' => $index + 1]);
        });

        $this->loadLessons();
        $this->touchSaved();
    }

    public function addQuestion(int $lessonIndex): void
    {
        $lesson = $this->lessonAt($lessonIndex);

        $question = Question::query()->create([
            'lesson_id' => $lesson->id,
            'prompt' => __('New question'),
            'position' => ((int) $lesson->questions()->max('position')) + 1,
        ]);

        // A question with a single option cannot be answered — start with a usable pair.
        foreach ([1, 2] as $position) {
            QuestionOption::query()->create([
                'question_id' => $question->id,
                'text' => '',
                'position' => $position,
            ]);
        }

        $this->loadLessons();
        $this->touchSaved();
    }

    public function removeQuestion(int $lessonIndex, int $questionIndex): void
    {
        $this->questionAt($lessonIndex, $questionIndex)->delete();
        $this->loadLessons();
        $this->touchSaved();
    }

    public function addOption(int $lessonIndex, int $questionIndex): void
    {
        $question = $this->questionAt($lessonIndex, $questionIndex);

        QuestionOption::query()->create([
            'question_id' => $question->id,
            'text' => '',
            'position' => ((int) $question->options()->max('position')) + 1,
        ]);

        $this->loadLessons();
        $this->touchSaved();
    }

    public function removeOption(int $lessonIndex, int $questionIndex, int $optionIndex): void
    {
        $this->optionAt($lessonIndex, $questionIndex, $optionIndex)->delete();
        $this->loadLessons();
        $this->touchSaved();
    }

    /** Single choice keeps exactly one correct option, so selecting one clears the others. */
    public function selectSingleCorrect(int $lessonIndex, int $questionIndex, int $optionIndex): void
    {
        $question = $this->questionAt($lessonIndex, $questionIndex);
        $option = $this->optionAt($lessonIndex, $questionIndex, $optionIndex);

        DB::transaction(function () use ($question, $option): void {
            $question->options()->update(['is_correct' => false]);
            $option->update(['is_correct' => true]);
        });

        $this->loadLessons();
        $this->touchSaved();
    }

    /** Opens a one-time upload slot at the provider and hands the URL to the browser. */
    public function requestUpload(int $lessonIndex, RequestVideoUpload $action): string
    {
        $upload = $action->handle($this->lessonAt($lessonIndex));

        $this->loadLessons();

        return $upload->uploadUrl;
    }

    public function uploadCompleted(int $lessonIndex): void
    {
        $lesson = $this->lessonAt($lessonIndex);

        $lesson->video?->update(['status' => VideoStatus::Processing]);

        $this->loadLessons();
        $this->touchSaved();
    }

    public function openVideoLibrary(int $lessonIndex, VideoLibrary $library): void
    {
        $this->lessonAt($lessonIndex);
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
            ->contains(fn (array $item): bool => hash_equals((string) $item['asset_id'], $assetId));
        abort_unless($listed, 404);

        $action->handle($this->lessonAt($this->videoLibraryLessonIndex), $assetId);

        $this->videoLibraryOpen = false;
        $this->videoLibraryLessonIndex = null;
        $this->videoLibraryItems = [];
        $this->loadLessons();
        $this->touchSaved();
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

        $this->loadLessons();
    }

    public function confirmPublish(CourseVersionValidator $validator): void
    {
        $this->authorize('publish', $this->course);

        $this->publishProblems = $validator->problems($this->version);
        $this->confirmingPublish = true;
    }

    public function publish(PublishCourseVersion $action): void
    {
        $this->authorize('publish', $this->course);

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

    public function with(CourseVersionValidator $validator, VideoProvider $videoProvider, EligibleModuleCatalog $moduleCatalog): array
    {
        $moduleGroups = $moduleCatalog->forCourseEditor($this->course->company, auth()->user(), $this->moduleSearch);

        return [
            'problems' => $validator->problems($this->version),
            'openAssignmentCount' => UserTrainingAssignment::query()
                ->where('course_id', $this->course->id)
                ->where('course_version_id', '!=', $this->version->id)
                ->open()
                ->count(),
            'usingFakeVideoProvider' => $videoProvider->key() === 'local_fake',
            'hasEncodingVideo' => collect($this->lessons)->contains(
                fn (array $lesson): bool => in_array($lesson['video']['status'] ?? null, ['uploading', 'processing'], true)
            ),
            'moduleGroups' => $moduleGroups,
            'selectedModules' => $this->version->moduleCompositions()->with('moduleVersion')->get(),
        ];
    }

    // ---------------------------------------------------------------- persistence

    private function saveCourseField(string $field, mixed $value): void
    {
        if (! in_array($field, ['code', 'title', 'description'], true)) {
            return;
        }

        $this->validate([
            'courseForm.code' => ['required', 'string', 'max:40', 'unique:courses,code,'.$this->course->id],
            'courseForm.title' => ['required', 'string', 'max:200'],
            'courseForm.description' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->course->update([$field => $field === 'code' ? strtoupper(trim((string) $value)) : $value]);
        $this->courseForm['code'] = $this->course->code;

        // The version carries its own title so a published edition keeps the wording the
        // employee actually saw. While it is still a draft there is nothing to preserve
        // yet, so it simply tracks the course.
        if ($field === 'title') {
            $this->version->update(['title' => $this->course->title]);
            $this->versionForm['title'] = $this->course->title;
        }

        $this->touchSaved();
    }

    private function saveVersionField(string $field, mixed $value): void
    {
        if (! in_array($field, ['title', 'description'], true)) {
            return;
        }

        $this->validate([
            'versionForm.title' => ['required', 'string', 'max:200'],
            'versionForm.description' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->version->update([$field => $value]);
        $this->touchSaved();
    }

    private function saveLessonField(int $index, string $field, mixed $value): void
    {
        $allowed = ['title', 'description', 'is_required', 'minimum_watch_percentage', 'passing_score'];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        $this->validate([
            "lessons.{$index}.title" => ['required', 'string', 'max:200'],
            "lessons.{$index}.description" => ['nullable', 'string', 'max:2000'],
            "lessons.{$index}.minimum_watch_percentage" => ['required', 'integer', 'min:1', 'max:100'],
            "lessons.{$index}.passing_score" => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $this->lessonAt($index)->update([$field => $value]);
        $this->touchSaved();
    }

    private function saveQuestionField(int $lessonIndex, int $questionIndex, string $field, mixed $value): void
    {
        if (! in_array($field, ['prompt', 'type', 'max_attempts'], true)) {
            return;
        }

        $this->validate([
            "lessons.{$lessonIndex}.questions.{$questionIndex}.prompt" => ['required', 'string', 'max:1000'],
            "lessons.{$lessonIndex}.questions.{$questionIndex}.max_attempts" => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $question = $this->questionAt($lessonIndex, $questionIndex);
        $question->update([$field => $value]);

        // Narrowing to single choice must leave at most one correct answer behind.
        if ($field === 'type' && $value === QuestionType::SingleChoice->value) {
            $keep = $question->options()->where('is_correct', true)->orderBy('position')->first();
            $question->options()->where('is_correct', true)->update(['is_correct' => false]);
            $keep?->update(['is_correct' => true]);
            $this->loadLessons();
        }

        $this->touchSaved();
    }

    private function saveOptionField(int $lessonIndex, int $questionIndex, int $optionIndex, string $field, mixed $value): void
    {
        if (! in_array($field, ['text', 'is_correct'], true)) {
            return;
        }

        $this->optionAt($lessonIndex, $questionIndex, $optionIndex)->update([$field => $value]);
        $this->touchSaved();
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

    private function resequenceLessons(): void
    {
        $this->version->lessons()->orderBy('position')->get()
            ->each(fn (Lesson $lesson, int $index) => $lesson->update(['position' => $index + 1]));
    }

    private function loadLessons(): void
    {
        $this->version->refresh();

        $this->lessons = $this->version->lessons()->with(['video', 'questions.options'])->get()
            ->map(fn (Lesson $lesson): array => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'position' => $lesson->position,
                'is_required' => $lesson->is_required,
                'minimum_watch_percentage' => $lesson->minimum_watch_percentage,
                'passing_score' => $lesson->passing_score,
                'video' => $lesson->video === null ? null : [
                    'status' => $lesson->video->status->value,
                    'status_label' => $lesson->video->status->label(),
                    'pill' => $lesson->video->status->pillModifier(),
                    'duration' => $lesson->video->formattedDuration(),
                ],
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

    private function touchSaved(): void
    {
        $this->savedAt = now()->format('H:i:s');
    }

    private function persistModuleComposition(UpdateCourseModuleComposition $action): void
    {
        $action->handle($this->version, $this->selectedModuleVersionIds, auth()->user());
        $this->resetErrorBag('modules');
        $this->touchSaved();
    }
};
?>

<div class="admin-page space-y-7" @if ($hasEncodingVideo) wire:poll.5s="syncVideos" @endif>
    <x-page-hero
        :kicker="__('ui.draft_version', ['number' => $version->version_number])"
        :title="__('ui.course_editor')"
        :description="__('ui.course_editor_description')">
        <span class="text-xs font-semibold text-[#8a9298]" wire:loading.remove wire:target="addLesson,addQuestion,addOption,syncVideos">
            @if ($savedAt)
                {{ __('ui.saved_at', ['time' => $savedAt]) }}
            @else
                {{ __('ui.autosave_hint') }}
            @endif
        </span>
        <span class="text-xs font-semibold text-[#1c6b84]" wire:loading wire:target="addLesson,addQuestion,addOption,syncVideos">{{ __('Saving…') }}</span>
        <flux:button :href="route('courses.show', ['course' => $course])" wire:navigate variant="ghost" size="sm">{{ __('ui.back_to_course') }}</flux:button>
        @can('publish', $course)
            <flux:button wire:click="confirmPublish" variant="primary" class="admin-primary-action">{{ __('Publish version') }}</flux:button>
        @endcan
    </x-page-hero>

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

    {{-- Course details --}}
    <section class="form-panel rounded-[20px] border border-[#dde3e7] p-5 sm:p-6">
        <h2 class="text-base font-bold text-[#262d33]">{{ __('Course details') }}</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-[160px_minmax(0,1fr)]">
            <flux:input wire:model.blur="courseForm.code" class="admin-control" :label="__('Code')" />
            <flux:input wire:model.blur="courseForm.title" class="admin-control" :label="__('Title')" />
        </div>
        <flux:textarea wire:model.blur="courseForm.description" class="admin-control mt-4" :label="__('Description')" rows="2" />
    </section>

    {{-- Version settings --}}
    <section class="form-panel rounded-[20px] border border-[#dde3e7] p-5 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-bold text-[#262d33]">{{ __('Version settings') }}</h2>
            <span class="status-pill status-pill--neutral">{{ __('Draft') }}</span>
        </div>
        <div class="mt-4">
            <flux:textarea wire:model.blur="versionForm.description" class="admin-control" :label="__('Description shown to the employee')" rows="3" />
            <p class="mt-2 text-xs text-[#8a9298]">{{ __('ui.version_description_note') }}</p>
        </div>
        <p class="mt-4 border-t border-[#eef1f4] pt-4 text-xs text-[#8a9298]">{{ __('ui.completion_rule_note') }}</p>
    </section>

    {{-- Immutable module composition --}}
    <section class="detail-card space-y-5">
        <div>
            <p class="admin-kicker">{{ __('Reusable content') }}</p>
            <h2 class="mt-1 text-xl font-bold tracking-tight text-[#242a2f]">{{ __('Course Modules') }}</h2>
            <p class="mt-1 text-sm text-[#707a80]">{{ __('Compose this draft from published Company Modules and Shared Modules.') }}</p>
        </div>
        @error('modules') <flux:callout variant="danger" :heading="$message" /> @enderror
        <div class="space-y-2" aria-live="polite">
            @forelse ($selectedModules as $index => $composition)
                <div class="flex items-center gap-3 rounded-[16px] border border-[#e4e9ec] bg-[#f8fafb] p-3" wire:key="composition-{{ $composition->id }}">
                    <span class="grid size-8 place-items-center rounded-xl bg-[#e4f0f5] text-sm font-bold text-[#1c6b84]">{{ $index + 1 }}</span>
                    <div class="min-w-0 flex-1"><p class="truncate font-semibold">{{ $composition->moduleVersion->title }}</p><p class="text-xs text-[#7d878d]">{{ $composition->moduleVersion->is_shared ? __('Shared Module') : __('Company Module') }} · {{ __('Version :number', ['number' => $composition->moduleVersion->version_number]) }}</p></div>
                    <flux:button wire:click="moveModule({{ $index }}, -1)" variant="ghost" size="sm" icon="chevron-up" :aria-label="__('Move module up')" :disabled="$index === 0" />
                    <flux:button wire:click="moveModule({{ $index }}, 1)" variant="ghost" size="sm" icon="chevron-down" :aria-label="__('Move module down')" :disabled="$index === $selectedModules->count() - 1" />
                    <flux:button wire:click="removeModule({{ $index }})" variant="ghost" size="sm" icon="trash" :aria-label="__('Remove module')" />
                </div>
            @empty
                <x-empty-state :title="__('No modules selected')" :description="__('Choose a published module below. Legacy lessons remain available during migration.')" />
            @endforelse
        </div>
        <flux:input wire:model.live.debounce.300ms="moduleSearch" type="search" :label="__('Search modules')" :placeholder="__('Search by title or code')" />
        <div wire:loading.delay wire:target="moduleSearch" role="status" class="text-sm text-[#5f6a71]">{{ __('Searching modules…') }}</div>
        <div class="grid gap-5 md:grid-cols-2" wire:loading.remove.delay wire:target="moduleSearch">
            @foreach (['company' => __('Company Modules'), 'shared' => __('Shared Modules')] as $group => $label)
                <div><h3 class="text-sm font-bold">{{ $label }}</h3><div class="mt-3 space-y-2">
                    @forelse ($moduleGroups[$group] as $module)
                        <div class="flex items-center justify-between rounded-[16px] border border-[#e4e9ec] p-3" wire:key="picker-{{ $group }}-{{ $module->id }}"><div><p class="font-semibold">{{ $module->title }}</p><p class="text-xs text-[#7d878d]">{{ $module->code }} · {{ $group === 'shared' ? __('Managed by platform') : __('Managed by company') }}</p></div><flux:button wire:click="addModule({{ $module->id }})" wire:loading.attr="disabled" variant="ghost" size="sm" :disabled="in_array($module->id, $selectedModuleVersionIds, true)">{{ __('Add module') }}</flux:button></div>
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
            <flux:button wire:click="addLesson" variant="ghost" size="sm" icon="plus">{{ __('Add lesson') }}</flux:button>
        </div>

        @forelse ($lessons as $lessonIndex => $lesson)
            <div class="rounded-[22px] border border-[#dde3e7] bg-white shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]" wire:key="lesson-{{ $lesson['id'] }}">
                <div class="flex flex-wrap items-center gap-3 p-4 sm:p-5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-[#e4f0f5] text-sm font-bold text-[#1c6b84]">{{ $lesson['position'] }}</span>

                    <button type="button" wire:click="toggleLesson({{ $lesson['id'] }})" class="min-w-0 flex-1 text-left">
                        <span class="block truncate font-bold text-[#262d33]">{{ $lesson['title'] ?: __('Untitled lesson') }}</span>
                        <span class="mt-0.5 block text-xs text-[#8a9298]">
                            {{ $lesson['video']['duration'] ?? __('No video') }}
                            · {{ trans_choice('ui.questions_count', count($lesson['questions']), ['count' => count($lesson['questions'])]) }}
                        </span>
                    </button>

                    @if ($lesson['video'])
                        <span class="status-pill {{ $lesson['video']['pill'] }}">{{ $lesson['video']['status_label'] }}</span>
                    @else
                        <span class="status-pill status-pill--warning">{{ __('No video') }}</span>
                    @endif

                    <div class="flex items-center gap-1">
                        <flux:button wire:click="moveLesson({{ $lessonIndex }}, -1)" variant="ghost" size="sm" icon="chevron-up" :aria-label="__('Move up')" :disabled="$lessonIndex === 0" />
                        <flux:button wire:click="moveLesson({{ $lessonIndex }}, 1)" variant="ghost" size="sm" icon="chevron-down" :aria-label="__('Move down')" :disabled="$lessonIndex === count($lessons) - 1" />
                        <flux:button wire:click="removeLesson({{ $lessonIndex }})" wire:confirm="{{ __('ui.confirm_remove_lesson') }}" variant="ghost" size="sm" icon="trash" :aria-label="__('Remove lesson')" />
                        <flux:button wire:click="toggleLesson({{ $lesson['id'] }})" variant="ghost" size="sm" :icon="in_array($lesson['id'], $expanded, true) ? 'chevron-double-up' : 'chevron-double-down'" :aria-label="__('Expand lesson')" />
                    </div>
                </div>

                @if (in_array($lesson['id'], $expanded, true))
                    <div class="border-t border-[#eef1f4] p-4 sm:p-5">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <flux:input wire:model.blur="lessons.{{ $lessonIndex }}.title" class="admin-control" :label="__('Lesson title')" />
                            <div class="grid grid-cols-2 gap-4">
                                <flux:field>
                                    <x-field-label :hint="__('ui.watch_threshold_help')">{{ __('Watch threshold (%)') }}</x-field-label>
                                    <flux:input type="number" min="1" max="100" wire:model.blur="lessons.{{ $lessonIndex }}.minimum_watch_percentage" class="admin-control" />
                                    <flux:error name="lessons.{{ $lessonIndex }}.minimum_watch_percentage" />
                                </flux:field>
                                <flux:field>
                                    <x-field-label :hint="__('ui.passing_score_help')">{{ __('Passing score (%)') }}</x-field-label>
                                    <flux:input type="number" min="1" max="100" wire:model.blur="lessons.{{ $lessonIndex }}.passing_score" class="admin-control" />
                                    <flux:error name="lessons.{{ $lessonIndex }}.passing_score" />
                                </flux:field>
                            </div>
                        </div>
                        <flux:textarea wire:model.blur="lessons.{{ $lessonIndex }}.description" class="admin-control mt-4" :label="__('Lesson description')" rows="2" />
                        <flux:checkbox wire:model.live="lessons.{{ $lessonIndex }}.is_required" class="mt-4" :label="__('Required to complete the course')" />

                        {{-- Video --}}
                        <div class="mt-5 rounded-[18px] border border-[#e4e9ec] bg-[#f8fafb] p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3" x-data="lessonVideoUpload({{ $lessonIndex }}, {{ Js::from(['fileTooLarge' => __('This video is larger than 200 MB. Select a smaller file.')]) }})">
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-[#262d33]">{{ __('Video') }}</p>
                                    <p class="mt-0.5 text-xs text-[#8a9298]">
                                        @if ($lesson['video'])
                                            {{ $lesson['video']['status_label'] }} · {{ $lesson['video']['duration'] }}
                                        @else
                                            {{ __('ui.video_upload_hint') }}
                                        @endif
                                    </p>
                                    <template x-if="uploading">
                                        <div class="mt-2 w-56">
                                            <div class="h-2 overflow-hidden rounded-full bg-[#e2e8ec]">
                                                <div class="h-full rounded-full bg-[#1c6b84]" :style="`width: ${progress}%`"></div>
                                            </div>
                                            <p class="mt-1 text-[11px] font-semibold text-[#1c6b84]" x-text="`${progress}%`"></p>
                                        </div>
                                    </template>
                                    <p class="mt-2 text-[11px] font-semibold text-[#b23a3a]" x-show="error" x-text="error"></p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <input type="file" accept="video/*" class="hidden" x-ref="file" @change="start($event)">
                                    <flux:button wire:click="openVideoLibrary({{ $lessonIndex }})" variant="ghost" size="sm" icon="rectangle-stack">
                                        {{ __('Video library') }}
                                    </flux:button>
                                    <flux:button variant="ghost" size="sm" x-on:click="$refs.file.click()" ::disabled="uploading">
                                        {{ $lesson['video'] ? __('Replace video') : __('Upload video') }}
                                    </flux:button>
                                </div>
                            </div>
                        </div>

                        {{-- Questions --}}
                        <div class="mt-5">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-bold text-[#262d33]">{{ __('Assessment') }}</p>
                                <flux:button wire:click="addQuestion({{ $lessonIndex }})" variant="ghost" size="sm" icon="plus">{{ __('Add question') }}</flux:button>
                            </div>

                            <div class="mt-3 space-y-3">
                                @forelse ($lesson['questions'] as $questionIndex => $question)
                                    <div class="rounded-[18px] border border-[#e4e9ec] p-4" wire:key="question-{{ $question['id'] }}">
                                        <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_180px_140px_auto] lg:items-end">
                                            <flux:input wire:model.blur="lessons.{{ $lessonIndex }}.questions.{{ $questionIndex }}.prompt" class="admin-control" :label="__('Question')" />
                                            <flux:select wire:model.live="lessons.{{ $lessonIndex }}.questions.{{ $questionIndex }}.type" class="admin-control" :label="__('Type')">
                                                @foreach (App\Enums\QuestionType::cases() as $case)
                                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                                @endforeach
                                            </flux:select>
                                            <flux:field>
                                                <x-field-label :hint="__('ui.attempts_help')">{{ __('Attempts') }}</x-field-label>
                                                <flux:input type="number" min="1" max="10" wire:model.blur="lessons.{{ $lessonIndex }}.questions.{{ $questionIndex }}.max_attempts" class="admin-control" />
                                                <flux:error name="lessons.{{ $lessonIndex }}.questions.{{ $questionIndex }}.max_attempts" />
                                            </flux:field>
                                            <flux:button wire:click="removeQuestion({{ $lessonIndex }}, {{ $questionIndex }})" wire:confirm="{{ __('ui.confirm_remove_question') }}" variant="ghost" size="sm" icon="trash" :aria-label="__('Remove question')" />
                                        </div>

                                        <div class="mt-4 space-y-2">
                                            @foreach ($question['options'] as $optionIndex => $option)
                                                <div class="flex items-center gap-3" wire:key="option-{{ $option['id'] }}">
                                                    @if ($question['type'] === App\Enums\QuestionType::SingleChoice->value)
                                                        <input type="radio"
                                                            wire:click="selectSingleCorrect({{ $lessonIndex }}, {{ $questionIndex }}, {{ $optionIndex }})"
                                                            @checked($option['is_correct'])
                                                            name="correct-{{ $question['id'] }}"
                                                            class="size-4 border-[#8e989f] text-[#1c6b84] focus:ring-[#3e8ba3]"
                                                            aria-label="{{ __('Correct answer') }}">
                                                    @else
                                                        <input type="checkbox"
                                                            wire:model.live="lessons.{{ $lessonIndex }}.questions.{{ $questionIndex }}.options.{{ $optionIndex }}.is_correct"
                                                            class="size-4 rounded border-[#8e989f] text-[#1c6b84] focus:ring-[#3e8ba3]"
                                                            aria-label="{{ __('Correct answer') }}">
                                                    @endif
                                                    <flux:input wire:model="lessons.{{ $lessonIndex }}.questions.{{ $questionIndex }}.options.{{ $optionIndex }}.text" class="admin-control flex-1" :placeholder="__('Answer option')" />
                                                    <flux:button wire:click="removeOption({{ $lessonIndex }}, {{ $questionIndex }}, {{ $optionIndex }})" variant="ghost" size="sm" icon="x-mark" :aria-label="__('Remove option')" />
                                                </div>
                                            @endforeach
                                        </div>

                                        <flux:button wire:click="addOption({{ $lessonIndex }}, {{ $questionIndex }})" variant="ghost" size="sm" class="mt-3" icon="plus">{{ __('Add option') }}</flux:button>
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
                <flux:button wire:click="addLesson" variant="primary" class="admin-primary-action">{{ __('Add lesson') }}</flux:button>
            </x-empty-state>
        @endforelse
    </section>

    <flux:modal wire:model.self="videoLibraryOpen" class="max-w-3xl">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Video library') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Select a ready video from Cloudflare Stream to reuse in this lesson.') }}</flux:text>
            </div>

            <div class="flex gap-2">
                <flux:input
                    wire:model="videoLibrarySearch"
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
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="radio" wire:model="assignmentUpdateMode" value="replace_open" class="mt-1 size-4 border-[#8e989f] text-[#1c6b84] focus:ring-[#3e8ba3]">
                        <span><strong class="block text-sm text-[#262d33]">{{ __('ui.replace_open_assignments') }}</strong><span class="text-xs text-[#707a80]">{{ __('ui.replace_open_assignments_help') }}</span></span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="radio" wire:model="assignmentUpdateMode" value="new_only" class="mt-1 size-4 border-[#8e989f] text-[#1c6b84] focus:ring-[#3e8ba3]">
                        <span><strong class="block text-sm text-[#262d33]">{{ __('ui.keep_existing_assignments') }}</strong><span class="text-xs text-[#707a80]">{{ __('ui.keep_existing_assignments_help') }}</span></span>
                    </label>
                </fieldset>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button x-on:click="$wire.confirmingPublish = false" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="publish" variant="primary" class="admin-primary-action" :disabled="count($publishProblems) > 0">
                    {{ __('Publish version') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
