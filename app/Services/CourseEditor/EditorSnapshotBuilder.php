<?php

namespace App\Services\CourseEditor;

use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Enums\ModuleVersionStatus;
use App\Enums\PlatformPermission;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\User;
use App\Models\Video;
use App\Services\Courses\CoursePreviewPanel;
use App\Services\Courses\CourseVersionComposition;
use App\Services\Courses\LessonContentRenderer;
use App\Services\Platform\PlatformAccess;
use App\Services\Video\VideoLibrary;
use Illuminate\Support\Facades\Gate;

final class EditorSnapshotBuilder
{
    public function __construct(
        private readonly EditorRevision $revisions,
        private readonly LessonContentRenderer $renderer,
        private readonly CourseVersionComposition $composition,
        private readonly VideoLibrary $videos,
        private readonly PlatformAccess $platformAccess,
        private readonly CoursePreviewPanel $previews,
    ) {}

    public function forCompanyCourse(int $courseId, User $actor): EditorSnapshot
    {
        $course = Course::query()->whereKey($courseId)->whereNotNull('company_id')->where('is_shared', false)->firstOrFail();
        Gate::forUser($actor)->authorize('update', $course);
        $version = $course->versions()->where('status', CourseVersionStatus::Draft->value)->firstOrFail();
        Gate::forUser($actor)->authorize('updateVersion', $version);
        $state = $this->composition->inspect($version);
        $lessons = $version->lessons()->with(['video', 'questions.options'])->orderBy('position')->orderBy('id')->get();
        $compositions = $version->moduleCompositions()->orderBy('position')->orderBy('id')->get();
        $preservedModules = $state['reusableRows']->map(function ($composition): ?array {
            $module = $composition->moduleVersion;
            if ($module === null) {
                return null;
            }
            $module->loadMissing(['video', 'questions.options']);

            return $this->record($module, 'module');
        })->filter()->values()->all();

        return new EditorSnapshot(
            'company-course',
            $course->id,
            $this->course($course),
            $this->version($version),
            $lessons->map(fn ($lesson): array => $this->record($lesson, 'lesson'))->all(),
            ['root' => $this->revisions->forCompanyCourse($course, $version, $lessons, $compositions)],
            new EditorCapabilities(true, true, $state['mode'] !== CourseVersionComposition::Mixed, true, Gate::forUser($actor)->allows('publish', $course), false, [
                'discard' => __('Company drafts use the existing course lifecycle.'),
            ]),
            $state['mode'],
            route('courses.show', ['course' => $course, 'company' => $course->company()->firstOrFail()]),
            $preservedModules,
            $this->previews->forCompany($course, $version, $actor),
        );
    }

    public function forSharedCourse(int $courseId, Account $actor): EditorSnapshot
    {
        $this->authorizePlatform($actor, PlatformPermission::SharedCoursesUpdate);
        $course = Course::query()->withoutGlobalScopes()->whereKey($courseId)->whereNull('company_id')->where('is_shared', true)->where('status', '!=', CourseStatus::Archived->value)->firstOrFail();
        $version = $course->versions()->where('status', CourseVersionStatus::Draft->value)->where('publication_kind', 'manual')->firstOrFail();
        $compositions = $version->moduleCompositions()->with(['moduleVersion.video', 'moduleVersion.questions.options'])->orderBy('position')->orderBy('id')->get();
        abort_if($compositions->contains(fn ($composition): bool => $composition->moduleVersion === null || $composition->moduleVersion->status !== ModuleVersionStatus::Draft || $composition->moduleVersion->lineage_archived_at !== null), 409);

        $moduleRevisions = $compositions->mapWithKeys(fn ($composition): array => [
            'record:'.$composition->lesson_id => $this->revisions->forSharedModule($composition->moduleVersion),
        ])->all();

        return new EditorSnapshot(
            'shared-course',
            $course->id,
            $this->course($course),
            $this->version($version),
            $compositions->map(fn ($composition): array => [
                ...$this->record($composition->moduleVersion, 'module'),
                'composition_id' => $composition->id,
            ])->all(),
            ['root' => $this->revisions->forSharedCourse($course, $version, $compositions), ...$moduleRevisions],
            new EditorCapabilities(true, true, true, true, true, true),
            'modules',
            route('platform.shared-courses.show', ['course' => $course]),
            [],
            $this->previews->forPlatform($course, $version, $actor),
        );
    }

    public function forSharedModule(int $moduleId, Account $actor): EditorSnapshot
    {
        $this->authorizePlatform($actor, PlatformPermission::SharedModulesUpdate);
        $module = Module::query()->withoutGlobalScopes()->whereKey($moduleId)->whereNull('company_id')->where('is_shared', true)->whereNull('lineage_archived_at')->firstOrFail();
        $version = $module->versions()->where('status', ModuleVersionStatus::Draft->value)->whereNull('lineage_archived_at')->firstOrFail();
        $version->load(['video', 'questions.options']);

        return new EditorSnapshot(
            'shared-module',
            $module->id,
            null,
            $this->version($version),
            [$this->record($version, 'module')],
            ['root' => $this->revisions->forSharedModule($version)],
            new EditorCapabilities(false, true, true, true, true, true, [
                'course-details' => __('Standalone modules do not have course details.'),
                'course-publication' => __('Standalone modules use module publication.'),
            ]),
            'module',
            route('platform.shared-modules.show', ['module' => $module]),
        );
    }

    public function companyVersion(int $courseId, User $actor): CourseVersion
    {
        $course = $this->companyRoot($courseId, $actor);
        $version = $course->versions()->where('status', CourseVersionStatus::Draft->value)->firstOrFail();
        Gate::forUser($actor)->authorize('updateVersion', $version);

        return $version;
    }

    public function companyRoot(int $courseId, User $actor): Course
    {
        $course = Course::query()->whereKey($courseId)->whereNotNull('company_id')->where('is_shared', false)->firstOrFail();
        Gate::forUser($actor)->authorize('update', $course);

        return $course;
    }

    public function sharedCourseVersion(int $courseId, Account $actor): CourseVersion
    {
        $course = $this->sharedCourseRoot($courseId, $actor);

        return $course->versions()->where('status', CourseVersionStatus::Draft->value)->where('publication_kind', 'manual')->firstOrFail();
    }

    public function sharedCourseRoot(int $courseId, Account $actor): Course
    {
        $this->authorizePlatform($actor, PlatformPermission::SharedCoursesUpdate);

        return Course::query()->withoutGlobalScopes()->whereKey($courseId)->whereNull('company_id')->where('is_shared', true)->where('status', '!=', CourseStatus::Archived->value)->firstOrFail();
    }

    public function sharedModuleVersion(int $moduleId, Account $actor): ModuleVersion
    {
        $this->authorizePlatform($actor, PlatformPermission::SharedModulesUpdate);
        $module = Module::query()->withoutGlobalScopes()->whereKey($moduleId)->whereNull('company_id')->where('is_shared', true)->whereNull('lineage_archived_at')->firstOrFail();

        return $module->versions()->where('status', ModuleVersionStatus::Draft->value)->whereNull('lineage_archived_at')->firstOrFail();
    }

    public function companyRecord(int $courseId, int $recordId, User $actor): Lesson
    {
        return $this->companyVersion($courseId, $actor)->lessons()->findOrFail($recordId);
    }

    public function sharedCourseRecord(int $courseId, int $recordId, Account $actor): ModuleVersion
    {
        $version = $this->sharedCourseVersion($courseId, $actor);
        $version->moduleCompositions()->where('lesson_id', $recordId)->firstOrFail();

        return ModuleVersion::query()->whereKey($recordId)->whereNull('company_id')->where('is_shared', true)->where('status', ModuleVersionStatus::Draft->value)->whereNull('lineage_archived_at')->firstOrFail();
    }

    public function companyVideo(int $courseId, int $recordId, int $videoId, User $actor): Video
    {
        return $this->companyRecord($courseId, $recordId, $actor)->videos()->findOrFail($videoId);
    }

    public function sharedCourseVideo(int $courseId, int $recordId, int $videoId, Account $actor): Video
    {
        return $this->sharedCourseRecord($courseId, $recordId, $actor)->videos()->findOrFail($videoId);
    }

    public function sharedModuleVideo(int $moduleId, int $videoId, Account $actor): Video
    {
        return $this->sharedModuleVersion($moduleId, $actor)->videos()->findOrFail($videoId);
    }

    /** @return array<string, mixed> */
    private function course(Course $course): array
    {
        return [
            'id' => $course->id,
            'key' => 'course:'.$course->id,
            'code' => $course->code,
            'title' => $course->title,
            'description' => (string) $course->description,
        ];
    }

    /** @return array<string, mixed> */
    private function version(CourseVersion|ModuleVersion $version): array
    {
        return [
            'id' => $version->id,
            'key' => 'version:'.$version->id,
            'version_number' => $version->version_number,
            'title' => $version->title,
            'description' => (string) $version->description,
        ];
    }

    /** @return array<string, mixed> */
    private function record($record, string $type): array
    {
        return [
            'id' => $record->id,
            'key' => ($type === 'module' ? 'module-version:' : 'lesson:').$record->id,
            'type' => $type,
            'title' => $record->title,
            'description' => (string) $record->description,
            'content_markdown' => $this->renderer->editorContent((string) $record->content_markdown),
            'is_required' => (bool) $record->is_required,
            'minimum_watch_percentage' => (int) $record->minimum_watch_percentage,
            'passing_score' => (int) $record->passing_score,
            'position' => (int) $record->position,
            'video' => $this->video($record->video),
            'questions' => $record->questions->sortBy('position')->values()->map(fn ($question): array => [
                'id' => $question->id,
                'key' => 'question:'.$question->id,
                'prompt' => $question->prompt,
                'type' => $question->type->value,
                'max_attempts' => (int) $question->max_attempts,
                'position' => (int) $question->position,
                'options' => $question->options->sortBy('position')->values()->map(fn ($option): array => [
                    'id' => $option->id,
                    'key' => 'option:'.$option->id,
                    'text' => $option->text,
                    'is_correct' => (bool) $option->is_correct,
                    'position' => (int) $option->position,
                ])->all(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function video($video): ?array
    {
        if ($video === null) {
            return null;
        }

        return [
            'id' => $video->id,
            'status' => $video->status->value,
            'status_label' => $video->status->label(),
            'duration' => $video->formattedDuration(),
            'preview' => rescue(fn (): ?array => $this->videos->preview($video), null, report: false),
        ];
    }

    private function authorizePlatform(Account $actor, PlatformPermission $permission): void
    {
        $authorized = $this->platformAccess->account();
        abort_unless($authorized !== null && $authorized->is($actor), 403);
        $this->platformAccess->authorizePermission($permission);
    }
}
