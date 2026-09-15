<?php

namespace App\Livewire\CourseEditor\Contexts;

use App\Actions\Documents\UploadLessonDocument;
use App\Actions\Modules\DiscardModuleDraft;
use App\Actions\Modules\MutateSharedModuleAssessmentStructure;
use App\Actions\Modules\PublishModuleVersion;
use App\Actions\Modules\ReorderSharedModuleAssessment;
use App\Actions\Modules\SaveSharedModuleEditorDraft;
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
use App\Services\Modules\ModulePropagationImpact;
use App\Services\Modules\ModuleVersionValidator;
use App\Services\Platform\PlatformAccess;
use App\Services\Video\VideoLibrary;
use LogicException;

final class SharedModuleEditorContext implements EditorContext
{
    public function __construct(
        private readonly EditorSnapshotBuilder $snapshots,
        private readonly PlatformAccess $access,
        private readonly VideoLibrary $videos,
    ) {}

    public function name(): string
    {
        return 'shared-module';
    }

    public function open(int $rootId): EditorSnapshot
    {
        return $this->snapshots->forSharedModule($rootId, $this->actor());
    }

    public function save(int $rootId, EditorSaveCommand $command): EditorSaveResult
    {
        $before = $command->expectedRevision();
        $version = $this->snapshots->sharedModuleVersion($rootId, $this->actor());
        abort_unless(count($command->records) === 1 && $command->records[0]['id'] === $version->id, 422);
        $record = [
            ...$command->records[0],
            'content_dirty' => in_array($command->records[0]['key'], $command->dirtyContentKeys, true),
        ];
        app(SaveSharedModuleEditorDraft::class)->handle($version, $this->actor(), $record, $before);
        $snapshot = $this->open($rootId);

        return new EditorSaveResult($snapshot, $command->localGeneration, ! hash_equals($before, $snapshot->revisions['root']));
    }

    public function performStructure(int $rootId, string $operation, array $payload): EditorSnapshot
    {
        $version = $this->snapshots->sharedModuleVersion($rootId, $this->actor());
        $revision = (string) ($payload['record_revision'] ?? $payload['expected_revision'] ?? '');
        match ($operation) {
            'add-question' => app(MutateSharedModuleAssessmentStructure::class)->handle($version, $this->actor(), 'add_question', $version->id, null, $revision),
            'remove-question' => app(MutateSharedModuleAssessmentStructure::class)->handle($version, $this->actor(), 'remove_question', $version->id, (int) $payload['question_id'], $revision),
            'add-option' => app(MutateSharedModuleAssessmentStructure::class)->handle($version, $this->actor(), 'add_option', (int) $payload['question_id'], null, $revision),
            'remove-option' => app(MutateSharedModuleAssessmentStructure::class)->handle($version, $this->actor(), 'remove_option', (int) $payload['question_id'], (int) $payload['option_id'], $revision),
            'reorder-questions' => app(ReorderSharedModuleAssessment::class)->handle($version, $this->actor(), 'questions', null, $payload['ordered_ids'], $revision),
            'reorder-options' => app(ReorderSharedModuleAssessment::class)->handle($version, $this->actor(), 'options', (int) $payload['question_id'], $payload['ordered_ids'], $revision),
            default => throw new LogicException('Unsupported standalone module structure operation.'),
        };

        return $this->open($rootId);
    }

    public function performMedia(int $rootId, string $operation, array $payload): array
    {
        if ($operation === 'upload-pdf') {
            return app(UploadLessonDocument::class)->handle($payload['upload'], $this->name(), $rootId, (int) $payload['record_id'], $this->actor(), (string) $payload['revision']);
        }
        if (in_array($operation, ['list-images', 'select-image', 'upload-image'], true)) {
            $actor = $this->actor();
            $this->snapshots->sharedModuleVersion($rootId, $actor);

            return match ($operation) {
                'list-images' => ['items' => app(EditorContentImageLibrary::class)->platform()],
                'select-image' => app(EditorContentImageLibrary::class)->platformImage((int) $payload['image_id']),
                'upload-image' => (function () use ($actor, $payload): array {
                    $image = app(UploadEditorContentImage::class)->handle($payload['upload'], platformActor: $actor);

                    return ['id' => $image->id, 'name' => $image->name, 'url' => $image->url()];
                })(),
            };
        }
        $version = $this->snapshots->sharedModuleVersion($rootId, $this->actor());

        return match ($operation) {
            'request-upload' => (function () use ($version, $payload): array {
                $upload = app(RequestVideoUpload::class)->forPlatformEditor($version, $this->actor(), (string) ($payload['record_revision'] ?? ''));

                return ['url' => $upload->uploadUrl, 'video_id' => $upload->videoId, 'asset_id' => $upload->assetId];
            })(),
            'attach' => ['video_id' => app(LinkExistingVideo::class)->forPlatformEditor($version, (string) $payload['asset_id'], $this->actor(), (string) ($payload['record_revision'] ?? ''))->id],
            'remove' => (function () use ($version, $payload): array {
                app(DetachEditorVideo::class)->forPlatformEditor($version, (int) $payload['video_id'], $this->actor(), (string) ($payload['record_revision'] ?? ''));

                return [];
            })(),
            'fail-upload' => ['video_id' => app(FailVideoUpload::class)->forPlatformEditor($this->snapshots->sharedModuleVideo($rootId, (int) $payload['video_id'], $this->actor()), $this->actor(), (string) ($payload['record_revision'] ?? ''))->id],
            'sync' => (function () use ($rootId, $payload): array {
                $actor = $this->actor();
                $video = app(SyncVideoAsset::class)->forPlatformEditor(
                    $this->snapshots->sharedModuleVideo($rootId, (int) $payload['video_id'], $actor),
                    $actor,
                    (string) ($payload['record_revision'] ?? ''),
                );

                return ['video_id' => $video->id, 'status' => $video->status->value];
            })(),
            default => throw new LogicException('Unsupported standalone module media operation.'),
        };
    }

    public function publish(int $rootId, array $payload = []): void
    {
        $actor = $this->access->authorizePermission(PlatformPermission::SharedModulesPublish);
        app(PublishModuleVersion::class)->handle($this->snapshots->sharedModuleVersion($rootId, $actor), $actor, (bool) ($payload['restart_in_progress'] ?? false));
    }

    public function discard(int $rootId, array $payload = []): void
    {
        $version = $this->snapshots->sharedModuleVersion($rootId, $this->actor());
        app(DiscardModuleDraft::class)->handle($version, $this->actor(), (string) ($payload['reason'] ?? ''), (string) ($payload['revision'] ?? ''));
    }

    public function availableRecords(int $rootId, string $search = ''): array
    {
        $this->snapshots->sharedModuleVersion($rootId, $this->actor());

        return [];
    }

    public function videoLibrary(int $rootId, int $recordId, string $search = ''): array
    {
        $actor = $this->actor();
        $version = $this->snapshots->sharedModuleVersion($rootId, $actor);
        abort_unless($version->id === $recordId, 404);

        return $this->videos->forPlatform($search);
    }

    public function publicationImpact(int $rootId): array
    {
        $actor = $this->access->authorizePermission(PlatformPermission::SharedModulesPublish);

        return app(ModulePropagationImpact::class)->summarize($this->snapshots->sharedModuleVersion($rootId, $actor));
    }

    public function publicationProblems(int $rootId): array
    {
        $actor = $this->access->authorizePermission(PlatformPermission::SharedModulesPublish);

        return app(ModuleVersionValidator::class)->problems($this->snapshots->sharedModuleVersion($rootId, $actor));
    }

    public function publicationConfirmation(int $rootId): array
    {
        $actor = $this->access->authorizePermission(PlatformPermission::SharedModulesPublish);
        $this->snapshots->sharedModuleVersion($rootId, $actor);

        return [
            'title' => __('Publish Shared Module'),
            'body' => __('Publishing updates future training while preserving completed history. Restarting in-progress assignments does not rewrite completed evidence.'),
            'submit_label' => __('Publish Shared Module'),
            'problems_title' => __('This version is not ready to publish.'),
            'assignment_mode' => false,
            'restart_in_progress' => true,
            'restart_description' => __('When selected, existing progress is not transferred to replacement assignments.'),
        ];
    }

    private function actor(): Account
    {
        return $this->access->authorize();
    }
}
