<?php

namespace App\Livewire\CourseEditor\Contexts;

use App\Actions\Courses\AttachExistingSharedModule;
use App\Actions\Courses\DiscardSharedCourseDraft;
use App\Actions\Courses\PublishSharedCourseDraft;
use App\Actions\Courses\RemoveSharedCourseModule;
use App\Actions\Courses\ReorderSharedCourseModules;
use App\Actions\Courses\SaveSharedCourseEditorDraft;
use App\Actions\Modules\CreateAndAttachSharedModule;
use App\Actions\Modules\MutateSharedModuleAssessmentStructure;
use App\Actions\Modules\ReorderSharedModuleAssessment;
use App\Actions\Videos\DetachEditorVideo;
use App\Actions\Videos\FailVideoUpload;
use App\Actions\Videos\LinkExistingVideo;
use App\Actions\Videos\RequestVideoUpload;
use App\Actions\Videos\SyncVideoAsset;
use App\Actions\Videos\UploadEditorContentImage;
use App\Enums\PlatformPermission;
use App\Models\Account;
use App\Services\CourseEditor\EditorContentImageLibrary;
use App\Services\CourseEditor\EditorSaveCommand;
use App\Services\CourseEditor\EditorSaveResult;
use App\Services\CourseEditor\EditorSnapshot;
use App\Services\CourseEditor\EditorSnapshotBuilder;
use App\Services\Courses\CourseVersionValidator;
use App\Services\Modules\ModuleVersionValidator;
use App\Services\Platform\PlatformAccess;
use App\Services\SharedContent\SharedContentCatalog;
use App\Services\Video\VideoLibrary;
use LogicException;

final class SharedCourseEditorContext implements EditorContext
{
    public function __construct(
        private readonly EditorSnapshotBuilder $snapshots,
        private readonly PlatformAccess $access,
        private readonly VideoLibrary $videos,
    ) {}

    public function name(): string
    {
        return 'shared-course';
    }

    public function open(int $rootId): EditorSnapshot
    {
        return $this->snapshots->forSharedCourse($rootId, $this->actor());
    }

    public function save(int $rootId, EditorSaveCommand $command): EditorSaveResult
    {
        $before = $command->expectedRevision();
        $version = $this->snapshots->sharedCourseVersion($rootId, $this->actor());
        $course = $this->snapshots->sharedCourseRoot($rootId, $this->actor());
        $records = collect($command->records)->map(fn (array $record): array => [
            ...$record,
            'content_dirty' => in_array($record['key'], $command->dirtyContentKeys, true),
        ])->all();
        $moduleRevisions = collect($records)->mapWithKeys(fn (array $record): array => [
            $record['id'] => $command->expectedRevision('record:'.$record['id']),
        ])->all();
        app(SaveSharedCourseEditorDraft::class)->handle(
            $course,
            $version,
            $this->actor(),
            $command->course ?? [],
            $command->version,
            $records,
            $before,
            $moduleRevisions,
        );
        $snapshot = $this->open($rootId);

        return new EditorSaveResult($snapshot, $command->localGeneration, ! hash_equals($before, $snapshot->revisions['root']));
    }

    public function performStructure(int $rootId, string $operation, array $payload): EditorSnapshot
    {
        $version = $this->snapshots->sharedCourseVersion($rootId, $this->actor());
        $record = isset($payload['record_id']) ? $this->snapshots->sharedCourseRecord($rootId, (int) $payload['record_id'], $this->actor()) : null;
        $revision = (string) ($payload['expected_revision'] ?? '');

        match ($operation) {
            'attach-existing-record' => app(AttachExistingSharedModule::class)->handle($version, (int) $payload['module_version_id'], $this->actor(), $revision),
            'create-record' => app(CreateAndAttachSharedModule::class)->handle($version, $this->actor(), (string) $payload['code'], (string) $payload['title'], $payload['description'] ?? null, $revision),
            'remove-record' => (function () use ($version, $payload): void {
                $action = app(RemoveSharedCourseModule::class);
                $action->handle($version, (int) $payload['composition_id'], $this->actor(), (string) $payload['reason'], (string) $payload['expected_revision']);
            })(),
            'reorder-records' => app(ReorderSharedCourseModules::class)->handle($version, $this->actor(), $payload['ordered_ids'], $revision),
            'add-question' => app(MutateSharedModuleAssessmentStructure::class)->handle($record, $this->actor(), 'add_question', $record->id, null, $this->recordRevision($payload, $record->id)),
            'remove-question' => app(MutateSharedModuleAssessmentStructure::class)->handle($record, $this->actor(), 'remove_question', $record->id, (int) $payload['question_id'], $this->recordRevision($payload, $record->id)),
            'add-option' => app(MutateSharedModuleAssessmentStructure::class)->handle($record, $this->actor(), 'add_option', (int) $payload['question_id'], null, $this->recordRevision($payload, $record->id)),
            'remove-option' => app(MutateSharedModuleAssessmentStructure::class)->handle($record, $this->actor(), 'remove_option', (int) $payload['question_id'], (int) $payload['option_id'], $this->recordRevision($payload, $record->id)),
            'reorder-questions' => app(ReorderSharedModuleAssessment::class)->handle($record, $this->actor(), 'questions', null, $payload['ordered_ids'], $this->recordRevision($payload, $record->id)),
            'reorder-options' => app(ReorderSharedModuleAssessment::class)->handle($record, $this->actor(), 'options', (int) $payload['question_id'], $payload['ordered_ids'], $this->recordRevision($payload, $record->id)),
            default => throw new LogicException('Unsupported shared course structure operation.'),
        };

        return $this->open($rootId);
    }

    public function performMedia(int $rootId, string $operation, array $payload): array
    {
        if (in_array($operation, ['list-images', 'select-image', 'upload-image'], true)) {
            $actor = $this->actor();
            $this->snapshots->sharedCourseRoot($rootId, $actor);

            return match ($operation) {
                'list-images' => ['items' => app(EditorContentImageLibrary::class)->platform()],
                'select-image' => app(EditorContentImageLibrary::class)->platformImage((int) $payload['image_id']),
                'upload-image' => (function () use ($actor, $payload): array {
                    $image = app(UploadEditorContentImage::class)->handle($payload['upload'], platformActor: $actor);

                    return ['id' => $image->id, 'name' => $image->name, 'url' => $image->url()];
                })(),
            };
        }
        $record = $this->snapshots->sharedCourseRecord($rootId, (int) $payload['record_id'], $this->actor());

        return $this->media($rootId, $record, $operation, $payload);
    }

    public function publish(int $rootId, array $payload = []): void
    {
        $actor = $this->access->authorizePermission(PlatformPermission::SharedModulesPublish);
        app(PublishSharedCourseDraft::class)->handle($this->snapshots->sharedCourseVersion($rootId, $actor), $actor, (bool) ($payload['restart_in_progress'] ?? false));
    }

    public function discard(int $rootId, array $payload = []): void
    {
        $version = $this->snapshots->sharedCourseVersion($rootId, $this->actor());
        app(DiscardSharedCourseDraft::class)->handle($version, $this->actor(), (string) ($payload['reason'] ?? ''), (string) ($payload['revision'] ?? ''));
    }

    public function availableRecords(int $rootId, string $search = ''): array
    {
        $this->snapshots->sharedCourseRoot($rootId, $this->actor());

        return ['shared' => app(SharedContentCatalog::class)->availableModules($search)->map(fn ($module): array => [
            'id' => $module->id,
            'code' => $module->code,
            'title' => $module->title,
            'owner' => 'shared',
        ])->values()->all()];
    }

    public function videoLibrary(int $rootId, int $recordId, string $search = ''): array
    {
        $actor = $this->actor();
        $this->snapshots->sharedCourseRoot($rootId, $actor);
        $this->snapshots->sharedCourseRecord($rootId, $recordId, $actor);

        return $this->videos->forPlatform($search);
    }

    public function publicationImpact(int $rootId): array
    {
        $actor = $this->access->authorizePermission(PlatformPermission::SharedModulesPublish);

        return app(SharedContentCatalog::class)->coursePublicationImpact($this->snapshots->sharedCourseRoot($rootId, $actor));
    }

    public function publicationProblems(int $rootId): array
    {
        $actor = $this->access->authorizePermission(PlatformPermission::SharedModulesPublish);
        $version = $this->snapshots->sharedCourseVersion($rootId, $actor);
        $moduleProblems = $version->moduleCompositions()->with('moduleVersion')->get()
            ->pluck('moduleVersion')
            ->filter()
            ->flatMap(fn ($module): array => app(ModuleVersionValidator::class)->problems($module))
            ->all();

        return array_values(array_unique([
            ...$moduleProblems,
            ...app(CourseVersionValidator::class)->problemsBeforeSharedModulePublication($version),
        ]));
    }

    public function publicationConfirmation(int $rootId): array
    {
        $actor = $this->access->authorizePermission(PlatformPermission::SharedModulesPublish);
        $this->snapshots->sharedCourseRoot($rootId, $actor);

        return [
            'title' => __('Publish course and module changes'),
            'body' => __('Publishing updates future training while preserving completed history. Restarting in-progress assignments does not rewrite completed evidence.'),
            'submit_label' => __('Publish course and module changes'),
            'problems_title' => __('This draft is not ready to publish'),
            'assignment_mode' => false,
            'restart_in_progress' => true,
            'restart_description' => __('When selected, existing progress is not transferred to replacement assignments.'),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function media(int $rootId, $record, string $operation, array $payload): array
    {
        return match ($operation) {
            'request-upload' => (function () use ($record, $payload): array {
                $upload = app(RequestVideoUpload::class)->forPlatformEditor($record, $this->actor(), $this->recordRevision($payload, $record->id));

                return ['url' => $upload->uploadUrl, 'video_id' => $upload->videoId, 'asset_id' => $upload->assetId];
            })(),
            'attach' => ['video_id' => app(LinkExistingVideo::class)->forPlatformEditor($record, (string) $payload['asset_id'], $this->actor(), $this->recordRevision($payload, $record->id))->id],
            'remove' => (function () use ($record, $payload): array {
                app(DetachEditorVideo::class)->forPlatformEditor($record, (int) $payload['video_id'], $this->actor(), $this->recordRevision($payload, $record->id));

                return [];
            })(),
            'fail-upload' => ['video_id' => app(FailVideoUpload::class)->forPlatformEditor($this->snapshots->sharedCourseVideo($rootId, $record->id, (int) $payload['video_id'], $this->actor()), $this->actor(), $this->recordRevision($payload, $record->id))->id],
            'sync' => (function () use ($rootId, $record, $payload): array {
                $actor = $this->actor();
                $video = app(SyncVideoAsset::class)->forPlatformEditor(
                    $this->snapshots->sharedCourseVideo($rootId, $record->id, (int) $payload['video_id'], $actor),
                    $actor,
                    $this->recordRevision($payload, $record->id),
                );

                return ['video_id' => $video->id, 'status' => $video->status->value];
            })(),
            default => throw new LogicException('Unsupported shared course media operation.'),
        };
    }

    private function recordRevision(array $payload, int $recordId): string
    {
        return (string) ($payload['record_revision'] ?? $payload['revisions']['record:'.$recordId] ?? '');
    }

    private function actor(): Account
    {
        return $this->access->authorize();
    }
}
