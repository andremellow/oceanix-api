<?php

namespace App\Livewire\CourseEditor\Contexts;

use App\Actions\Courses\AddDirectCourseLesson;
use App\Actions\Courses\MutateCourseAssessmentStructure;
use App\Actions\Courses\PublishCourseVersion;
use App\Actions\Courses\RemoveDirectCourseLesson;
use App\Actions\Courses\ReorderDirectCourseContent;
use App\Actions\Courses\SaveCompanyCourseEditorDraft;
use App\Actions\Courses\UpdateCourseModuleComposition;
use App\Actions\Documents\UploadLessonDocument;
use App\Actions\Videos\DetachEditorVideo;
use App\Actions\Videos\FailVideoUpload;
use App\Actions\Videos\LinkExistingVideo;
use App\Actions\Videos\RequestVideoUpload;
use App\Actions\Videos\SyncVideoAsset;
use App\Actions\Videos\UploadEditorContentImage;
use App\Models\User;
use App\Services\CourseEditor\EditorContentImageLibrary;
use App\Services\CourseEditor\EditorSaveCommand;
use App\Services\CourseEditor\EditorSaveResult;
use App\Services\CourseEditor\EditorSnapshot;
use App\Services\CourseEditor\EditorSnapshotBuilder;
use App\Services\Courses\CoursePublicationImpact;
use App\Services\Courses\CourseVersionValidator;
use App\Services\Modules\EligibleModuleCatalog;
use App\Services\Video\VideoLibrary;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class CompanyCourseEditorContext implements EditorContext
{
    public function __construct(private readonly EditorSnapshotBuilder $snapshots, private readonly VideoLibrary $videos) {}

    public function name(): string
    {
        return 'company-course';
    }

    public function open(int $rootId): EditorSnapshot
    {
        return $this->snapshots->forCompanyCourse($rootId, $this->actor());
    }

    public function save(int $rootId, EditorSaveCommand $command): EditorSaveResult
    {
        $version = $this->snapshots->companyVersion($rootId, $this->actor());
        $changed = app(SaveCompanyCourseEditorDraft::class)->handle($rootId, $version->id, $this->actor(), $command);

        return new EditorSaveResult($this->open($rootId), $command->localGeneration, $changed);
    }

    public function performStructure(int $rootId, string $operation, array $payload): EditorSnapshot
    {
        $actor = $this->actor();
        $version = $this->snapshots->companyVersion($rootId, $actor);
        $revision = (string) ($payload['expected_revision'] ?? '');

        match ($operation) {
            'add-record' => app(AddDirectCourseLesson::class)->handle($version, $actor, $revision),
            'remove-record' => app(RemoveDirectCourseLesson::class)->handle($version, $actor, (int) $payload['record_id'], $revision),
            'reorder-records' => app(ReorderDirectCourseContent::class)->handle($version, $actor, 'lessons', null, $payload['ordered_ids'], $revision),
            'add-question' => app(MutateCourseAssessmentStructure::class)->handle($version, $actor, 'add_question', (int) $payload['record_id'], null, $revision),
            'remove-question' => app(MutateCourseAssessmentStructure::class)->handle($version, $actor, 'remove_question', (int) $payload['record_id'], (int) $payload['question_id'], $revision),
            'add-option' => app(MutateCourseAssessmentStructure::class)->handle($version, $actor, 'add_option', (int) $payload['question_id'], null, $revision),
            'remove-option' => app(MutateCourseAssessmentStructure::class)->handle($version, $actor, 'remove_option', (int) $payload['question_id'], (int) $payload['option_id'], $revision),
            'reorder-questions' => app(ReorderDirectCourseContent::class)->handle($version, $actor, 'questions', (int) $payload['record_id'], $payload['ordered_ids'], $revision),
            'reorder-options' => app(ReorderDirectCourseContent::class)->handle($version, $actor, 'options', (int) $payload['question_id'], $payload['ordered_ids'], $revision),
            'change-composition' => app(UpdateCourseModuleComposition::class)->handle($version, $payload['ordered_ids'], $actor, $revision),
            default => throw new LogicException('Unsupported company course structure operation.'),
        };

        return $this->open($rootId);
    }

    public function performMedia(int $rootId, string $operation, array $payload): array
    {
        if ($operation === 'upload-pdf') {
            return app(UploadLessonDocument::class)->handle($payload['upload'], $this->name(), $rootId, (int) $payload['record_id'], $this->actor(), (string) $payload['revision']);
        }
        $actor = $this->actor();
        if (in_array($operation, ['list-images', 'select-image', 'upload-image'], true)) {
            return match ($operation) {
                'list-images' => ['items' => app(EditorContentImageLibrary::class)->company((int) $this->snapshots->companyRoot($rootId, $actor)->company_id)],
                'select-image' => app(EditorContentImageLibrary::class)->companyImage((int) $this->snapshots->companyRoot($rootId, $actor)->company_id, (int) $payload['image_id']),
                'upload-image' => (function () use ($rootId, $actor, $payload): array {
                    $image = app(UploadEditorContentImage::class)->handle($payload['upload'], $this->snapshots->companyRoot($rootId, $actor), $actor);

                    return ['id' => $image->id, 'name' => $image->name, 'url' => $image->url()];
                })(),
            };
        }
        $lesson = $this->snapshots->companyRecord($rootId, (int) $payload['record_id'], $actor);

        return match ($operation) {
            'request-upload' => (function () use ($lesson, $actor, $payload): array {
                $upload = app(RequestVideoUpload::class)->forCompanyEditor($lesson, $actor, (string) ($payload['expected_revision'] ?? ''));

                return ['url' => $upload->uploadUrl, 'video_id' => $upload->videoId, 'asset_id' => $upload->assetId];
            })(),
            'attach' => ['video_id' => app(LinkExistingVideo::class)->forCompanyEditor($lesson, (string) $payload['asset_id'], $actor, (string) ($payload['expected_revision'] ?? ''))->id],
            'remove' => (function () use ($lesson, $payload, $actor): array {
                app(DetachEditorVideo::class)->forCompanyEditor($lesson, (int) $payload['video_id'], $actor, (string) ($payload['expected_revision'] ?? ''));

                return [];
            })(),
            'fail-upload' => ['video_id' => app(FailVideoUpload::class)->forCompanyEditor($this->snapshots->companyVideo($rootId, $lesson->id, (int) $payload['video_id'], $actor), $actor, (string) ($payload['expected_revision'] ?? ''))->id],
            'sync' => (function () use ($rootId, $lesson, $payload, $actor): array {
                $video = app(SyncVideoAsset::class)->forCompanyEditor(
                    $this->snapshots->companyVideo($rootId, $lesson->id, (int) $payload['video_id'], $actor),
                    $actor,
                    (string) ($payload['expected_revision'] ?? ''),
                );

                return ['video_id' => $video->id, 'status' => $video->status->value];
            })(),
            default => throw new LogicException('Unsupported company course media operation.'),
        };
    }

    public function publish(int $rootId, array $payload = []): void
    {
        $actor = $this->actor();
        $course = $this->snapshots->companyRoot($rootId, $actor);
        Gate::forUser($actor)->authorize('publish', $course);
        $version = $this->snapshots->companyVersion($rootId, $actor);
        app(PublishCourseVersion::class)->handle($version, $actor, (bool) ($payload['replace_open'] ?? false));
    }

    public function discard(int $rootId, array $payload = []): void
    {
        throw new LogicException('Company drafts use the existing course lifecycle.');
    }

    public function availableRecords(int $rootId, string $search = ''): array
    {
        $course = $this->snapshots->companyRoot($rootId, $this->actor());

        return collect(app(EligibleModuleCatalog::class)->forCourseEditor($course->company()->firstOrFail(), $this->actor(), $search))
            ->map(fn ($modules): array => $modules->map(fn ($module): array => [
                'id' => $module->id,
                'code' => $module->code,
                'title' => $module->title,
                'owner' => $module->is_shared ? 'shared' : 'company',
            ])->values()->all())
            ->all();
    }

    public function videoLibrary(int $rootId, int $recordId, string $search = ''): array
    {
        $actor = $this->actor();
        $course = $this->snapshots->companyRoot($rootId, $actor);
        $this->snapshots->companyRecord($rootId, $recordId, $actor);

        return $this->videos->forCompany((int) $course->company_id, $search);
    }

    public function publicationImpact(int $rootId): array
    {
        $actor = $this->actor();
        $course = $this->snapshots->companyRoot($rootId, $actor);
        Gate::forUser($actor)->authorize('publish', $course);

        return app(CoursePublicationImpact::class)->forVersion($this->snapshots->companyVersion($rootId, $actor));
    }

    public function publicationProblems(int $rootId): array
    {
        $actor = $this->actor();
        $course = $this->snapshots->companyRoot($rootId, $actor);
        Gate::forUser($actor)->authorize('publish', $course);

        return app(CourseVersionValidator::class)->problems($this->snapshots->companyVersion($rootId, $actor));
    }

    public function publicationConfirmation(int $rootId): array
    {
        $actor = $this->actor();
        $course = $this->snapshots->companyRoot($rootId, $actor);
        Gate::forUser($actor)->authorize('publish', $course);
        $version = $this->snapshots->companyVersion($rootId, $actor);

        return [
            'title' => __('ui.publish_confirm_title', ['number' => $version->version_number]),
            'body' => __('ui.publish_confirm_body'),
            'submit_label' => __('Publish version'),
            'problems_title' => __('This draft is not ready to publish'),
            'assignment_mode' => true,
            'restart_in_progress' => false,
            'restart_description' => '',
        ];
    }

    private function actor(): User
    {
        return auth()->user() ?? abort(403);
    }
}
