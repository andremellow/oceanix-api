<?php

namespace App\Livewire\CourseEditor;

use App\Enums\VideoStatus;
use App\Exceptions\CoursePublicationException;
use App\Exceptions\VideoProviderException;
use App\Livewire\CourseEditor\Contexts\EditorContext;
use App\Services\CourseEditor\EditorSaveCommand;
use App\Services\CourseEditor\EditorSnapshot;
use App\Services\CourseEditor\EditorStagedState;
use App\Services\CourseEditor\EditorStagedStateRebaser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

abstract class EditorCoordinator extends Component
{
    use WithFileUploads;

    private EditorStagedStateRebaser $stagedStateRebaser;

    #[Locked]
    public int $editorRootId;

    #[Locked]
    public string $editorContextName = '';

    /** @var array<string, mixed> */
    public array $courseForm = [];

    /** @var array<string, mixed> */
    public array $versionForm = [];

    /** @var list<array<string, mixed>> */
    public array $records = [];

    /** @var array<string, string> */
    public array $revisions = [];

    /** @var array<string, mixed> */
    public array $capabilities = [];

    public string $compositionMode = 'direct';

    public string $editorCloseUrl = '';

    public array $preservedRecords = [];

    public ?array $previewPanel = null;

    /** @var list<string> */
    public array $expanded = [];

    public bool $editorDirty = false;

    public string $saveState = 'clean';

    public ?string $saveError = null;

    public ?string $errorKind = null;

    public int $localGeneration = 0;

    public int $savedGeneration = 0;

    public ?string $focusRecordKey = null;

    public ?string $focusAction = null;

    public int $focusGeneration = 0;

    public ?string $focusInvalidField = null;

    public int $focusInvalidGeneration = 0;

    /** @var list<string> */
    public array $dirtyContentKeys = [];

    public ?string $savedAt = null;

    public bool $uploadInProgress = false;

    /** @var array<string, array{record_id: int, video_id: int, asset_id: string, url: string, state: string, candidate_failed: bool, failure_kind: ?string}> */
    public array $activeUploads = [];

    /** @var array<string, array{record_id: int, record_key: string, title: string, filename: ?string, state: string, failure_kind: ?string}> */
    public array $uploadRows = [];

    public bool $videoLibraryOpen = false;

    public ?int $videoLibraryRecordId = null;

    public ?string $videoLibraryRecordKey = null;

    public string $videoLibrarySearch = '';

    /** @var list<array<string, mixed>> */
    public array $videoLibraryItems = [];

    public ?string $videoLibraryError = null;

    public bool $imageLibraryOpen = false;

    public ?string $imageLibraryRecordKey = null;

    /** @var list<array{id: int, name: string, url: string}> */
    public array $contentImages = [];

    public $contentImageUpload;

    public bool $pdfModalOpen = false;

    #[Locked]
    public ?string $pdfRecordKey = null;

    #[Locked]
    public ?string $pdfOperationToken = null;

    public string $pdfLinkText = '';

    public $pdfUpload;

    public string $pdfSearch = '';

    #[Locked]
    public array $pdfLibrary = [];

    #[Locked]
    public ?string $pdfLibraryError = null;

    #[Locked]
    public ?array $pdfArchiveConfirmation = null;

    public bool $pdfArchiveModalOpen = false;

    public string $pdfLibraryNotice = '';

    /** @var array<string, array{state: string, error: ?string, retry_token?: string, record_id?: int}> */
    public array $operations = [];

    /** @var array<string, array{kind: string, operation: string, payload: array<string, mixed>}> */
    #[Locked]
    public array $operationRetries = [];

    public bool $restartInProgress = false;

    public string $assignmentUpdateMode = 'keep_existing';

    public string $moduleSearch = '';

    public ?int $selectedModuleId = null;

    /** @var array<string, list<array<string, mixed>>> */
    public array $availableModuleGroups = [];

    public bool $newModuleModalOpen = false;

    /** @var array{code: string, title: string, description: string} */
    public array $newModuleForm = ['code' => '', 'title' => '', 'description' => ''];

    public ?string $newModuleError = null;

    /** @var array<string, int> */
    public array $publicationImpact = [];

    /** Backwards-compatible public projection name used by the shared-course view contract. */
    public array $impact = [];

    /** @var list<string> */
    public array $publicationProblems = [];

    /** @var array{title: string, body: string, submit_label: string, problems_title: string, assignment_mode: bool, restart_in_progress: bool, restart_description: string} */
    #[Locked]
    public array $publicationConfirmation = [
        'title' => '',
        'body' => '',
        'submit_label' => '',
        'problems_title' => '',
        'assignment_mode' => false,
        'restart_in_progress' => false,
        'restart_description' => '',
    ];

    public bool $confirmingPublish = false;

    public bool $confirmingReload = false;

    public bool $confirmingDestructive = false;

    #[Locked]
    public string $destructiveAction = '';

    #[Locked]
    public array $destructivePayload = [];

    #[Locked]
    public string $destructiveTitle = '';

    #[Locked]
    public string $destructiveConsequence = '';

    #[Locked]
    public string $destructiveSubmitLabel = '';

    #[Locked]
    public string $destructiveTargetKey = '';

    #[Locked]
    public string $destructiveExpectedRevision = '';

    #[Locked]
    public string $destructiveExpectedRecordRevision = '';

    public bool $confirmingModuleRemoval = false;

    #[Locked]
    public ?string $moduleRemovalRecordKey = null;

    #[Locked]
    public ?int $moduleRemovalCompositionId = null;

    #[Locked]
    public string $moduleRemovalRevision = '';

    #[Locked]
    public string $moduleRemovalRecordRevision = '';

    #[Locked]
    public string $moduleRemovalTitle = '';

    public string $moduleRemovalReason = '';

    abstract protected function editorContext(): EditorContext;

    public function boot(EditorStagedStateRebaser $stagedStateRebaser): void
    {
        $this->stagedStateRebaser = $stagedStateRebaser;
    }

    protected function mountEditor(int $rootId): void
    {
        $this->editorRootId = $rootId;
        $this->applySnapshot($this->editorContext()->open($rootId));
        $this->refreshSupportingProjections();
    }

    public function updated(string $property): void
    {
        if (preg_match('/^records\.(\d+)\.content_markdown$/', $property, $matches) === 1) {
            $key = $this->records[(int) $matches[1]]['key'] ?? null;
            if (is_string($key) && ! in_array($key, $this->dirtyContentKeys, true)) {
                $this->dirtyContentKeys[] = $key;
            }
        }
        if ($this->isAuthoredProperty($property)) {
            $this->markEditorDirty();
        }
    }

    public function searchAvailableModules(): void
    {
        $this->availableModuleGroups = $this->editorContext()->availableRecords($this->editorRootId, $this->moduleSearch);
    }

    public function addSelectedModule(): void
    {
        abort_if($this->selectedModuleId === null, 422);
        $selected = $this->selectedModuleId;
        $payload = $this->fixedContextName() === 'company-course'
            ? ['ordered_ids' => [...array_column($this->preservedRecords, 'id'), $selected]]
            : ['module_version_id' => $selected];
        $operation = $this->fixedContextName() === 'company-course' ? 'change-composition' : 'attach-existing-record';
        if ($this->runStructure($operation, $payload)) {
            $this->selectedModuleId = null;
            $this->searchAvailableModules();
        }
    }

    public function removeCompositionRecord(int $recordId): void
    {
        abort(404);
    }

    public function openNewModuleModal(): void
    {
        abort_unless($this->fixedContextName() === 'shared-course', 404);
        $this->newModuleForm = ['code' => '', 'title' => '', 'description' => ''];
        $this->newModuleError = null;
        $this->resetValidation('newModuleForm');
        $this->newModuleModalOpen = true;
    }

    public function createNewModule(): void
    {
        abort_unless($this->fixedContextName() === 'shared-course', 404);
        $data = $this->validate([
            'newModuleForm.code' => ['required', 'string', 'max:80'],
            'newModuleForm.title' => ['required', 'string', 'max:200'],
            'newModuleForm.description' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->newModuleError = null;
        if ($this->runStructure('create-record', $data['newModuleForm'], 'newModuleForm.code')) {
            $created = collect($this->records)->sortByDesc('id')->first();
            $this->newModuleModalOpen = false;
            $this->newModuleForm = ['code' => '', 'title' => '', 'description' => ''];
            $this->searchAvailableModules();
            if (is_array($created)) {
                $this->dispatch('shared-module-created', moduleId: $created['id']);
            }
        }
    }

    public function retryOperation(string $operationKey): void
    {
        $retry = $this->operationRetries[$operationKey] ?? null;
        abort_unless(is_array($retry), 409);
        unset($this->operationRetries[$operationKey]);

        match ($retry['kind']) {
            'structure' => $this->runStructure($retry['operation'], $retry['payload']),
            'media' => $this->runMedia($retry['operation'], $retry['payload']),
            default => abort(422),
        };
    }

    public function markEditorDirty(): void
    {
        $this->localGeneration++;
        $this->editorDirty = true;
        if (! in_array($this->saveState, ['validation-error', 'conflict', 'network-error', 'unknown-outcome', 'permission-lost'], true)) {
            $this->saveState = 'dirty';
        }
    }

    public function saveDraft(bool $close = false): void
    {
        if (in_array($this->saveState, ['saving', 'conflict', 'unknown-outcome', 'permission-lost'], true)) {
            return;
        }
        if ($close && $this->uploadInProgress) {
            $this->saveState = 'validation-error';
            $this->errorKind = 'validation-error';
            $this->saveError = __('Wait for active uploads to finish before closing.');

            return;
        }
        if (! $this->editorDirty) {
            if ($close) {
                $this->redirect($this->closeUrl(), navigate: true);
            }

            return;
        }

        $generation = $this->localGeneration;
        $this->saveState = 'saving';
        $this->saveError = null;
        $this->errorKind = null;

        try {
            $result = $this->editorContext()->save($this->editorRootId, new EditorSaveCommand(
                $this->courseForm === [] ? null : $this->courseForm,
                $this->versionForm,
                $this->records,
                $this->revisions,
                $generation,
                $this->dirtyContentKeys,
            ));
        } catch (ValidationException $exception) {
            $this->saveState = array_key_exists('revision', $exception->errors()) ? 'conflict' : 'validation-error';
            $this->errorKind = $this->saveState;
            $this->saveError = collect($exception->errors())->flatten()->first() ?? __('Some changes need attention.');
            $firstInvalid = null;
            if ($this->saveState === 'validation-error') {
                $this->resetErrorBag();
                foreach ($exception->errors() as $field => $messages) {
                    $mapped = $this->mapValidationField($field);
                    $firstInvalid ??= $mapped;
                    foreach ($messages as $message) {
                        $this->addError($mapped, $message);
                    }
                }
                $this->focusInvalidField = $firstInvalid;
                $this->focusInvalidGeneration++;
            }
            $this->dispatch('editor-save-finished', state: $this->saveState, generation: $generation, invalidField: $firstInvalid);

            return;
        } catch (AuthorizationException $exception) {
            $this->markPermissionLost($generation);

            return;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }
            $this->markPermissionLost($generation);

            return;
        } catch (LogicException $exception) {
            $this->saveState = 'conflict';
            $this->errorKind = 'conflict';
            $this->saveError = $exception->getMessage();
            $this->dispatch('editor-save-finished', state: $this->saveState, generation: $generation);

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->saveState = 'network-error';
            $this->errorKind = 'network';
            $this->saveError = __("Changes weren't saved. Check your connection and try again.");
            $this->dispatch('editor-save-finished', state: $this->saveState, generation: $generation);

            return;
        }

        if ($result->acknowledgedGeneration === $this->localGeneration) {
            $this->resetErrorBag();
            $this->applySnapshot($result->snapshot);
            $this->savedGeneration = $result->acknowledgedGeneration;
            $this->editorDirty = false;
            $this->dirtyContentKeys = [];
            $this->saveState = 'saved';
            $this->savedAt = now()->format('H:i:s');
        } else {
            $this->revisions = $result->snapshot->revisions;
            $this->saveState = 'dirty';
        }
        $this->dispatch('editor-saved', generation: $result->acknowledgedGeneration, changed: $result->changed);
        $this->dispatch('editor-save-finished', state: $this->saveState, generation: $result->acknowledgedGeneration);

        if ($close && ! $this->editorDirty) {
            $this->redirect($this->closeUrl(), navigate: true);
        }
    }

    public function retrySave(): void
    {
        $this->saveDraft(false);
    }

    public function confirmReloadLatest(): void
    {
        abort_unless(in_array($this->saveState, ['conflict', 'unknown-outcome'], true), 409);
        $this->confirmingReload = true;
    }

    public function reloadLatestDraft(): void
    {
        abort_unless($this->confirmingReload && in_array($this->saveState, ['conflict', 'unknown-outcome'], true), 409);

        try {
            $snapshot = $this->editorContext()->open($this->editorRootId);
        } catch (AuthorizationException $exception) {
            $this->markPermissionLost();

            return;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }
            $this->markPermissionLost();

            return;
        }

        $this->applySnapshot($snapshot);
        $this->editorDirty = false;
        $this->dirtyContentKeys = [];
        $this->saveState = 'clean';
        $this->saveError = null;
        $this->errorKind = null;
        $this->confirmingReload = false;
        $this->resetErrorBag();
        $this->dispatch('editor-draft-reloaded');
    }

    public function toggleRecord(string $recordKey): void
    {
        $this->expanded = in_array($recordKey, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$recordKey]))
            : [...$this->expanded, $recordKey];
    }

    public function addRecord(): void
    {
        $this->runStructure('add-record');
    }

    public function addLesson(): void
    {
        $this->addRecord();
    }

    public function removeRecord(int $recordId, ?int $compositionId = null, string $reason = ''): void
    {
        abort(404);
    }

    public function confirmModuleRemoval(int $recordId, int $compositionId): void
    {
        abort_unless($this->fixedContextName() === 'shared-course', 404);
        try {
            $this->guardCanonicalReplacement('remove-record', ['record_id' => $recordId], 'structure');
        } catch (ValidationException $exception) {
            $this->addError('moduleRemovalReason', collect($exception->errors())->flatten()->first());

            return;
        }
        $canonical = $this->canonicalRecord($recordId);
        if ($canonical === null) {
            return;
        }
        [$snapshot, $record] = $canonical;
        abort_unless((int) ($record['composition_id'] ?? 0) === $compositionId, 404);
        $this->moduleRemovalRecordKey = (string) $record['key'];
        $this->moduleRemovalCompositionId = $compositionId;
        $this->moduleRemovalRevision = $snapshot->revisions['root'] ?? '';
        $this->moduleRemovalRecordRevision = $snapshot->revisions['record:'.$recordId] ?? $this->moduleRemovalRevision;
        $this->moduleRemovalTitle = (string) $record['title'];
        $this->moduleRemovalReason = '';
        $this->resetValidation('moduleRemovalReason');
        $this->confirmingModuleRemoval = true;
    }

    public function removeConfirmedModule(): void
    {
        abort_unless($this->fixedContextName() === 'shared-course', 404);
        abort_unless($this->confirmingModuleRemoval, 409);
        $data = $this->validate(['moduleRemovalReason' => ['required', 'string', 'max:500']]);
        abort_if($this->moduleRemovalRecordKey === null || $this->moduleRemovalCompositionId === null, 404);
        $record = collect($this->records)->firstWhere('key', $this->moduleRemovalRecordKey);
        abort_unless(is_array($record) && (int) ($record['composition_id'] ?? 0) === $this->moduleRemovalCompositionId, 404);
        if (! $this->runStructure('remove-record', [
            'record_id' => (int) $record['id'],
            'composition_id' => $this->moduleRemovalCompositionId,
            'reason' => $data['moduleRemovalReason'],
            '_expected_revision' => $this->moduleRemovalRevision,
            '_record_revision' => $this->moduleRemovalRecordRevision,
        ], 'moduleRemovalReason')) {
            return;
        }

        $this->reset('confirmingModuleRemoval', 'moduleRemovalRecordKey', 'moduleRemovalCompositionId', 'moduleRemovalRevision', 'moduleRemovalRecordRevision', 'moduleRemovalTitle', 'moduleRemovalReason');
        session()->flash('status', __('Module removed from the draft. Its content was not deleted.'));
    }

    public function moveRecord(int $recordId, int $direction): void
    {
        $ids = array_column($this->records, 'id');
        $this->runStructure('reorder-records', [
            'ordered_ids' => $this->moved($ids, $recordId, $direction),
            '_focus_record_key' => $this->record($recordId)['key'],
            '_focus_action' => $direction < 0 ? 'move-up' : 'move-down',
        ]);
    }

    public function removeLesson(int $record): void
    {
        abort(404);
    }

    public function confirmLessonRemoval(int $recordId): void
    {
        $canonical = $this->canonicalRecord($recordId);
        if ($canonical === null) {
            return;
        }
        [$snapshot, $record] = $canonical;
        $target = trim((string) ($record['title'] ?? '')) ?: __('Untitled lesson');
        $this->prepareDestructive(
            'remove-record',
            ['record_id' => $recordId],
            __('Remove lesson “:target”?', ['target' => $target]),
            __('Its questions, answers, and current video association will be removed from this editable draft. Provider media will not be deleted.'),
            __('Remove lesson'),
            $snapshot,
        );
    }

    public function confirmQuestionDestruction(int $recordId, int $questionId): void
    {
        $canonical = $this->canonicalRecord($recordId);
        if ($canonical === null) {
            return;
        }
        [$snapshot, $record] = $canonical;
        $question = collect($record['questions'])->firstWhere('id', $questionId) ?? abort(404);
        $target = trim((string) ($question['prompt'] ?? '')) ?: __('Untitled question');
        $this->prepareDestructive('remove-question', ['record_id' => $recordId, 'question_id' => $questionId],
            __('Remove question “:target”?', ['target' => $target]),
            __('The question and all of its answers will be removed from the editable assessment immediately.'),
            __('Remove question'), $snapshot);
    }

    public function confirmAnswerDestruction(int $recordId, int $questionId, int $optionId): void
    {
        $canonical = $this->canonicalRecord($recordId);
        if ($canonical === null) {
            return;
        }
        [$snapshot, $record] = $canonical;
        $question = collect($record['questions'])->firstWhere('id', $questionId) ?? abort(404);
        $option = collect($question['options'])->firstWhere('id', $optionId) ?? abort(404);
        $target = trim((string) ($option['text'] ?? '')) ?: __('Untitled answer');
        $this->prepareDestructive('remove-option', ['record_id' => $recordId, 'question_id' => $questionId, 'option_id' => $optionId],
            __('Remove answer “:target”?', ['target' => $target]),
            __('This answer will be removed from the editable assessment immediately. The remaining answers are preserved.'),
            __('Remove answer'), $snapshot);
    }

    public function confirmReusableModuleDestruction(int $recordId): void
    {
        abort_unless($this->fixedContextName() === 'company-course', 404);
        $canonical = $this->canonicalRecord($recordId, preserved: true);
        if ($canonical === null) {
            return;
        }
        [$snapshot, $record] = $canonical;
        $target = trim((string) ($record['title'] ?? '')) ?: __('Untitled record');
        $this->prepareDestructive('remove-composition-record', ['record_id' => $recordId],
            __('Remove reusable module “:target”?', ['target' => $target]),
            __('Only this editable course association will be removed. The reusable module and all authored content remain available.'),
            __('Remove module'), $snapshot);
    }

    public function confirmVideoDestruction(int $recordId, int $videoId): void
    {
        $canonical = $this->canonicalRecord($recordId);
        if ($canonical === null) {
            return;
        }
        [$snapshot, $record] = $canonical;
        abort_unless((int) data_get($record, 'video.id') === $videoId, 404);
        $target = trim((string) ($record['title'] ?? '')) ?: __('Untitled record');
        $this->prepareDestructive('remove-video', ['record_id' => $recordId, 'video_id' => $videoId],
            __('Remove the video from “:target”?', ['target' => $target]),
            __('Only the editable draft association will be removed. Provider media will not be deleted.'),
            __('Remove video'), $snapshot);
    }

    public function performConfirmedDestructive(): void
    {
        abort_unless($this->confirmingDestructive, 409);
        $payload = $this->destructivePayload;
        $completed = match ($this->destructiveAction) {
            'remove-record' => $this->runStructure('remove-record', ['record_id' => (int) $payload['record_id'], '_expected_revision' => $this->destructiveExpectedRevision, '_record_revision' => $this->destructiveExpectedRecordRevision]),
            'remove-question' => $this->runStructure('remove-question', ['record_id' => (int) $payload['record_id'], 'question_id' => (int) $payload['question_id'], '_expected_revision' => $this->destructiveExpectedRevision, '_record_revision' => $this->destructiveExpectedRecordRevision]),
            'remove-option' => $this->runStructure('remove-option', ['record_id' => (int) $payload['record_id'], 'question_id' => (int) $payload['question_id'], 'option_id' => (int) $payload['option_id'], '_expected_revision' => $this->destructiveExpectedRevision, '_record_revision' => $this->destructiveExpectedRecordRevision]),
            'remove-composition-record' => $this->runStructure('change-composition', ['ordered_ids' => $this->confirmedCompositionIds((int) $payload['record_id']), '_expected_revision' => $this->destructiveExpectedRevision, '_record_revision' => $this->destructiveExpectedRecordRevision]),
            'remove-video' => $this->runMedia('remove', ['record_id' => (int) $payload['record_id'], 'video_id' => (int) $payload['video_id'], '_expected_revision' => $this->destructiveExpectedRevision, '_record_revision' => $this->destructiveExpectedRecordRevision]),
            default => abort(422),
        };
        if ($completed) {
            $this->reset('confirmingDestructive', 'destructiveAction', 'destructivePayload', 'destructiveTitle', 'destructiveConsequence', 'destructiveSubmitLabel', 'destructiveTargetKey', 'destructiveExpectedRevision', 'destructiveExpectedRecordRevision');
        }
    }

    public function cancelDestructiveConfirmation(string $model): void
    {
        if ($model === 'confirmingDestructive') {
            $this->reset('confirmingDestructive', 'destructiveAction', 'destructivePayload', 'destructiveTitle', 'destructiveConsequence', 'destructiveSubmitLabel', 'destructiveTargetKey', 'destructiveExpectedRevision', 'destructiveExpectedRecordRevision');

            return;
        }
        abort_unless($model === 'confirmingModuleRemoval', 404);
        $this->reset('confirmingModuleRemoval', 'moduleRemovalRecordKey', 'moduleRemovalCompositionId', 'moduleRemovalRevision', 'moduleRemovalRecordRevision', 'moduleRemovalTitle', 'moduleRemovalReason');
        $this->resetValidation('moduleRemovalReason');
    }

    public function moveLesson(int $record, int $direction): void
    {
        $this->moveRecord($this->resolvedRecordId($record), $direction);
    }

    public function addQuestion(int $recordId): void
    {
        $this->runStructure('add-question', ['record_id' => $this->resolvedRecordId($recordId)]);
    }

    public function removeQuestion(int $recordId, int $questionId): void
    {
        abort(404);
    }

    public function moveQuestion(int $recordId, int $questionId, int $direction): void
    {
        $record = $this->record($recordId);
        $ids = array_column($record['questions'], 'id');
        $this->runStructure('reorder-questions', [
            'record_id' => $recordId,
            'ordered_ids' => $this->moved($ids, $questionId, $direction),
            '_focus_record_key' => 'question:'.$questionId,
            '_focus_action' => $direction < 0 ? 'move-up' : 'move-down',
        ]);
    }

    public function addOption(int $recordId, int $questionId): void
    {
        $this->runStructure('add-option', ['record_id' => $recordId, 'question_id' => $questionId]);
    }

    public function removeOption(int $recordId, int $questionId, int $optionId): void
    {
        abort(404);
    }

    public function moveOption(int $recordId, int $questionId, int $optionId, int $direction): void
    {
        $question = collect($this->record($recordId)['questions'])->firstWhere('id', $questionId) ?? abort(404);
        $ids = array_column($question['options'], 'id');
        $this->runStructure('reorder-options', [
            'record_id' => $recordId,
            'question_id' => $questionId,
            'ordered_ids' => $this->moved($ids, $optionId, $direction),
            '_focus_record_key' => 'option:'.$optionId,
            '_focus_action' => $direction < 0 ? 'move-up' : 'move-down',
        ]);
    }

    /** @return array<string, mixed> */
    public function requestUpload(int $recordId, ?string $filename = null): array
    {
        $operationKey = 'upload:'.$recordId;
        $this->operationPending($operationKey, $this->operationMetadata('upload', ['record_id' => $recordId], 'media'));
        try {
            $staged = $this->captureStagedState();
            $this->guardUploadRequest($recordId);
            $upload = $this->editorContext()->performMedia($this->editorRootId, 'request-upload', [
                'record_id' => $recordId,
                'expected_revision' => $this->revisions['root'] ?? '',
                'record_revision' => $this->revisions['record:'.$recordId] ?? $this->revisions['root'] ?? '',
            ]);
            $this->applyRebasedSnapshot($this->editorContext()->open($this->editorRootId), $staged);
            $token = (string) Str::uuid();
            $this->activeUploads[$token] = [
                'record_id' => $recordId,
                ...$upload,
                'state' => 'uploading',
                'candidate_failed' => false,
                'failure_kind' => null,
            ];
            $record = $this->record($recordId);
            $this->uploadRows[$token] = [
                'record_id' => $recordId,
                'record_key' => (string) $record['key'],
                'title' => (string) $record['title'],
                'filename' => $this->safeUploadFilename($filename),
                'state' => 'uploading',
                'failure_kind' => null,
            ];
            $this->uploadInProgress = true;

            return ['url' => $upload['url'], 'token' => $token];
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? __('The video upload could not be started. Try again.');
            $conflict = array_key_exists('revision', $exception->errors());
            if ($conflict) {
                $this->saveState = 'conflict';
                $this->errorKind = 'conflict';
                $this->saveError = $message;
            }
            $this->operationFailed($operationKey, $message, $conflict
                ? ['retry_available' => false, 'retry_guidance' => __('Reload the latest draft only after copying or deliberately discarding your local values.')]
                : []);

            return [];
        } catch (Throwable $exception) {
            $this->operationFailed($operationKey, __('The video upload could not be started. Try again.'));
            throw $exception;
        }
    }

    public function uploadCompleted(int $recordId, ?string $uploadToken = null): void
    {
        $this->reconcileUpload($recordId, $uploadToken);
    }

    public function retryUpload(int $recordId, string $uploadToken): void
    {
        $identity = $this->uploadIdentity($recordId, $uploadToken);
        abort_unless(($identity['state'] ?? null) === 'failed', 409);
        try {
            $this->guardUploadRequest($recordId, $uploadToken);
        } catch (ValidationException $exception) {
            $this->operationFailed(
                'upload:'.$recordId,
                collect($exception->errors())->flatten()->first() ?? __('The video upload could not be retried.'),
                ['retry_token' => $uploadToken, 'record_id' => $recordId, 'retry_mode' => 'provider'],
            );

            return;
        }
        $this->activeUploads[$uploadToken]['state'] = 'retrying';
        $this->setUploadRowState($uploadToken, 'uploading');
        $this->uploadInProgress = true;
        $this->operationPending('upload:'.$recordId, $this->operationMetadata('upload', ['record_id' => $recordId], 'media'));
        $this->reconcileUpload($recordId, $uploadToken);
    }

    /** @return array{url?: string, token?: string} */
    public function retryUploadTransfer(int $recordId, string $uploadToken): array
    {
        $identity = $this->uploadIdentity($recordId, $uploadToken);
        abort_unless(($identity['state'] ?? null) === 'failed' && ($identity['failure_kind'] ?? null) === 'transfer', 409);
        $operationKey = 'upload:'.$recordId;
        $this->operationPending($operationKey, $this->operationMetadata('upload', ['record_id' => $recordId], 'media'));

        try {
            $staged = $this->captureStagedState();
            $this->guardUploadRequest($recordId, $uploadToken);
            $upload = $this->editorContext()->performMedia($this->editorRootId, 'request-upload', [
                'record_id' => $recordId,
                'expected_revision' => $this->revisions['root'] ?? '',
                'record_revision' => $this->revisions['record:'.$recordId] ?? $this->revisions['root'] ?? '',
            ]);
            $this->applyRebasedSnapshot($this->editorContext()->open($this->editorRootId), $staged);
            $this->activeUploads[$uploadToken] = [
                'record_id' => $recordId,
                ...$upload,
                'state' => 'uploading',
                'candidate_failed' => false,
                'failure_kind' => null,
            ];
            $this->setUploadRowState($uploadToken, 'uploading');
            $this->uploadInProgress = true;

            return ['url' => $upload['url'], 'token' => $uploadToken];
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            $this->operationFailed(
                $operationKey,
                collect($exception->errors())->flatten()->first() ?? __('The video upload could not be restarted. Try again.'),
                ['retry_token' => $uploadToken, 'record_id' => $recordId, 'retry_mode' => 'transfer'],
            );

            return [];
        } catch (Throwable $exception) {
            $this->operationFailed(
                $operationKey,
                __('The video upload could not be restarted. Try again.'),
                ['retry_token' => $uploadToken, 'record_id' => $recordId, 'retry_mode' => 'transfer'],
            );
            throw $exception;
        }
    }

    private function reconcileUpload(int $recordId, ?string $uploadToken): void
    {
        $token = $uploadToken ?? abort(422);
        $identity = $this->uploadIdentity($recordId, $token);
        $staged = $this->captureStagedState();
        try {
            $result = $this->editorContext()->performMedia($this->editorRootId, 'sync', [
                'record_id' => $recordId,
                'video_id' => $identity['video_id'],
                'expected_revision' => $this->revisions['root'] ?? '',
                'record_revision' => $this->revisions['record:'.$recordId] ?? $this->revisions['root'] ?? '',
            ]);
        } catch (VideoProviderException) {
            if (! ($identity['candidate_failed'] ?? false)) {
                try {
                    $this->editorContext()->performMedia($this->editorRootId, 'fail-upload', [
                        'record_id' => $recordId,
                        'video_id' => $identity['video_id'],
                        'expected_revision' => $this->revisions['root'] ?? '',
                        'record_revision' => $this->revisions['record:'.$recordId] ?? $this->revisions['root'] ?? '',
                    ]);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
            $this->activeUploads[$token]['state'] = 'failed';
            $this->activeUploads[$token]['candidate_failed'] = true;
            $this->activeUploads[$token]['failure_kind'] = 'provider';
            $this->setUploadRowState($token, 'failed', 'provider');
            $this->refreshUploadInProgress();
            $this->operationFailed(
                'upload:'.$recordId,
                __('The video provider could not finish this upload. The previous video remains attached. Try again.'),
                ['retry_token' => $token, 'record_id' => $recordId, 'retry_mode' => 'provider'],
            );
            $this->applyRebasedSnapshot($this->editorContext()->open($this->editorRootId), $staged);

            return;
        }
        if (($result['status'] ?? null) === VideoStatus::Failed->value) {
            $this->activeUploads[$token]['state'] = 'failed';
            $this->activeUploads[$token]['candidate_failed'] = true;
            $this->activeUploads[$token]['failure_kind'] = 'provider';
            $this->setUploadRowState($token, 'failed', 'provider');
            $this->refreshUploadInProgress();
            $this->operationFailed(
                'upload:'.$recordId,
                __('The video provider could not finish this upload. The previous video remains attached. Try again.'),
                ['retry_token' => $token, 'record_id' => $recordId, 'retry_mode' => 'provider'],
            );
            $this->applyRebasedSnapshot($this->editorContext()->open($this->editorRootId), $staged);

            return;
        }
        if (($result['status'] ?? null) !== VideoStatus::Ready->value) {
            $this->activeUploads[$token]['state'] = 'processing';
            $this->activeUploads[$token]['candidate_failed'] = false;
            $this->activeUploads[$token]['failure_kind'] = null;
            $this->setUploadRowState($token, 'processing');
            $this->refreshUploadInProgress();
            $this->applyRebasedSnapshot($this->editorContext()->open($this->editorRootId), $staged);

            return;
        }
        unset($this->activeUploads[$token]);
        $this->setUploadRowState($token, 'ready');
        $this->refreshUploadInProgress();
        $this->operationSucceeded('upload:'.$recordId);
        $this->applyRebasedSnapshot($this->editorContext()->open($this->editorRootId), $staged);
    }

    public function uploadFailed(int $recordId, ?string $uploadToken = null): void
    {
        $token = $uploadToken ?? abort(422);
        $identity = $this->uploadIdentity($recordId, $token);
        $staged = $this->captureStagedState();
        try {
            $this->editorContext()->performMedia($this->editorRootId, 'fail-upload', [
                'record_id' => $recordId,
                'video_id' => $identity['video_id'],
                'expected_revision' => $this->revisions['root'] ?? '',
                'record_revision' => $this->revisions['record:'.$recordId] ?? $this->revisions['root'] ?? '',
            ]);
        } catch (AuthorizationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
        }
        $this->activeUploads[$token]['state'] = 'failed';
        $this->activeUploads[$token]['candidate_failed'] = true;
        $this->activeUploads[$token]['failure_kind'] = 'transfer';
        $this->setUploadRowState($token, 'failed', 'transfer');
        $this->refreshUploadInProgress();
        $this->operationFailed(
            'upload:'.$recordId,
            __('The video upload failed. Try again.'),
            ['retry_token' => $token, 'record_id' => $recordId, 'retry_mode' => 'transfer'],
        );
        $this->applyRebasedSnapshot($this->editorContext()->open($this->editorRootId), $staged);
    }

    public function attachVideo(int $recordId, string $assetId): void
    {
        $this->runMedia('attach', ['record_id' => $recordId, 'asset_id' => $assetId]);
    }

    public function detachVideo(int $recordId, int $videoId): void
    {
        abort(404);
    }

    public function openEditorVideoLibrary(string $model): void
    {
        abort_unless(preg_match('/^(?:records|modules)\.(\d+)\.content_markdown$/', $model, $matches) === 1, 422);
        $record = $this->records[(int) $matches[1]] ?? abort(404);
        $this->videoLibraryRecordId = (int) $record['id'];
        $this->videoLibraryRecordKey = (string) $record['key'];
        $this->videoLibrarySearch = '';
        $this->videoLibraryError = null;
        $this->videoLibraryOpen = true;
        $this->loadVideoLibrary();
    }

    public function searchVideoLibrary(): void
    {
        abort_unless($this->videoLibraryOpen && $this->videoLibraryRecordId !== null, 404);
        $this->loadVideoLibrary();
    }

    public function selectLibraryVideo(string $assetId): void
    {
        abort_if($this->videoLibraryRecordId === null || $this->videoLibraryRecordKey === null, 404);
        $record = $this->record($this->videoLibraryRecordId);
        abort_unless(hash_equals((string) $record['key'], $this->videoLibraryRecordKey), 404);
        $item = collect($this->editorContext()->videoLibrary($this->editorRootId, $this->videoLibraryRecordId, $this->videoLibrarySearch))->first(
            fn (array $candidate): bool => hash_equals((string) $candidate['asset_id'], $assetId)
                && $candidate['status'] === VideoStatus::Ready->value,
        );
        abort_if($item === null, 404);

        if (! $this->runMedia('attach', ['record_id' => $this->videoLibraryRecordId, 'asset_id' => $assetId])) {
            return;
        }

        $recordIndex = collect($this->records)->search(fn (array $record): bool => $record['key'] === $this->videoLibraryRecordKey);
        if ($recordIndex !== false) {
            $this->dispatch('oceanix:insert-video',
                model: 'records.'.$recordIndex.'.content_markdown',
                previewUrl: $item['preview_url'],
                posterUrl: $item['thumbnail_url'],
                title: $item['title'],
                aspectRatio: $item['aspect_ratio'],
            );
        }
        $this->videoLibraryOpen = false;
        $this->videoLibraryItems = [];
    }

    public function openImageLibrary(string $model): void
    {
        abort_unless(preg_match('/^(?:records|modules)\.(\d+)\.content_markdown$/', $model, $matches) === 1, 422);
        $record = $this->records[(int) $matches[1]] ?? abort(404);
        $this->imageLibraryRecordKey = (string) $record['key'];
        $this->contentImages = $this->editorContext()->performMedia($this->editorRootId, 'list-images', [])['items'];
        $this->imageLibraryOpen = true;
        $this->resetValidation('contentImageUpload');
    }

    public function openPdfModal(string $model, string $text, string $token): void
    {
        abort_unless(preg_match('/^records\.(\d+)\.content_markdown$/', $model, $matches) === 1, 422);
        $record = $this->records[(int) $matches[1]] ?? abort(404);
        $this->editorContext()->open($this->editorRootId);
        $this->guardCanonicalReplacement('upload-pdf', ['record_id' => $record['id']], 'pdf');
        $this->reset('pdfUpload');
        $this->resetValidation('pdfUpload');
        $this->pdfRecordKey = $record['key'];
        $this->pdfOperationToken = $token;
        $this->pdfLinkText = $text;
        $this->pdfModalOpen = true;
        $this->pdfSearch = '';
        $this->pdfArchiveConfirmation = null;
        $this->pdfLibraryNotice = '';
        $this->loadPdfLibrary();
    }

    public function loadPdfLibrary(int $page = 1): void
    {
        abort_unless($this->pdfModalOpen, 422);
        $this->pdfLibraryError = null;
        $this->resetValidation('pdfLibrary');
        try {
            $this->pdfLibrary = $this->editorContext()->performMedia($this->editorRootId, 'list-pdfs', ['search' => $this->pdfSearch, 'page' => $page]);
        } catch (AuthorizationException|HttpExceptionInterface $exception) {
            $this->pdfLibrary = [];
            $this->pdfArchiveConfirmation = null;
            $this->pdfArchiveModalOpen = false;
            $this->pdfLibraryError = __('You do not have access to this PDF library.');
        } catch (Throwable $exception) {
            report($exception);
            $this->pdfLibraryError = __('The PDF library could not be loaded. Try again.');
        }
    }

    public function searchPdfs(): void
    {
        $this->validate(['pdfSearch' => ['string', 'max:240']]);
        $this->loadPdfLibrary();
    }

    public function clearPdfSearch(): void
    {
        $this->pdfSearch = '';
        $this->loadPdfLibrary();
    }

    public function reusePdf(string $publicId): void
    {
        abort_unless($this->pdfModalOpen && $this->pdfRecordKey && $this->pdfOperationToken && ! $this->pdfArchiveConfirmation, 422);
        $index = collect($this->records)->search(fn ($record) => $record['key'] === $this->pdfRecordKey);
        abort_if($index === false, 409);
        $record = $this->records[$index];
        $key = 'media:reuse-pdf:'.$this->pdfRecordKey;
        $this->operationPending($key, $this->operationMetadata('reuse-pdf', ['record_key' => $this->pdfRecordKey], 'media'));
        $this->resetValidation('pdfLibrary');
        try {
            $this->guardCanonicalReplacement('reuse-pdf', ['record_id' => $record['id']], 'pdf');
            $this->validate(['pdfLinkText' => ['nullable', 'string', 'max:100000']]);
            $document = $this->editorContext()->performMedia($this->editorRootId, 'reuse-pdf', [
                'document' => $publicId, 'record_id' => $record['id'],
                'revision' => $this->revisions['record:'.$record['id']] ?? $this->revisions['root'] ?? '',
            ]);
            $this->dispatch('oceanix:insert-pdf', model: 'records.'.$index.'.content_markdown', recordKey: $this->pdfRecordKey, token: $this->pdfOperationToken, reference: $document['reference'], label: $this->pdfLinkText === '' ? $document['name'] : $this->pdfLinkText);
            $this->operationSucceeded($key);
        } catch (Throwable $exception) {
            $message = $this->pdfFailure($exception, __('The PDF could not be reused. Try again.'));
            $this->addError('pdfLibrary', $message);
            $this->operationFailed($key, $message);
        }
    }

    public function requestArchivePdf(string $publicId): void
    {
        abort_unless($this->pdfModalOpen, 422);
        $this->loadPdfLibrary($this->pdfLibrary['current_page'] ?? 1);
        if ($this->pdfLibraryError) {
            return;
        }
        $row = collect($this->pdfLibrary['items'] ?? [])->firstWhere('id', $publicId);
        if (! $row || ! $row['can_archive']) {
            $this->pdfArchiveConfirmation = null;
            $this->pdfArchiveModalOpen = false;
            $this->addError('pdfLibrary', __('You do not have permission to archive this PDF.'));

            return;
        }
        $this->pdfArchiveConfirmation = ['id' => $row['id'], 'name' => $row['name']];
        $this->pdfArchiveModalOpen = true;
        $this->resetValidation('pdfArchive');
    }

    public function cancelArchivePdf(): void
    {
        $id = $this->pdfArchiveConfirmation['id'] ?? null;
        $this->pdfArchiveConfirmation = null;
        $this->dispatch('oceanix:pdf-archive-cancelled', id: $id);
        $this->pdfArchiveModalOpen = false;
    }

    public function archivePdf(): void
    {
        abort_unless($this->pdfModalOpen && $this->pdfArchiveConfirmation, 422);
        $this->resetValidation('pdfArchive');
        try {
            $this->editorContext()->performMedia($this->editorRootId, 'archive-pdf', ['document' => $this->pdfArchiveConfirmation['id']]);
            $items = array_column($this->pdfLibrary['items'] ?? [], 'id');
            $index = array_search($this->pdfArchiveConfirmation['id'], $items, true);
            $focus = $items[$index + 1] ?? $items[$index - 1] ?? null;
            $this->pdfArchiveConfirmation = null;
            $this->pdfArchiveModalOpen = false;
            $this->loadPdfLibrary($this->pdfLibrary['current_page'] ?? 1);
            $this->pdfLibraryNotice = __('PDF archived. Existing links still work.');
            $this->dispatch('oceanix:pdf-archived', id: $focus);
        } catch (Throwable $exception) {
            $this->addError('pdfArchive', $this->pdfFailure($exception, __('The PDF could not be archived. Try again.')));
            $this->dispatch('oceanix:pdf-archive-failed');
        }
    }

    private function pdfFailure(Throwable $exception, string $fallback): string
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->first();
        }
        if ($exception instanceof AuthorizationException || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403)) {
            $this->pdfArchiveConfirmation = null;
            $this->pdfArchiveModalOpen = false;
            $this->loadPdfLibrary($this->pdfLibrary['current_page'] ?? 1);
            $message = $this->pdfLibraryError ?? __('You do not have permission to perform this PDF action.');
            $this->addError('pdfLibrary', $message);
            $this->dispatch('oceanix:pdf-archive-cancelled', id: null);

            return $message;
        }
        if ($exception instanceof ModelNotFoundException || $exception instanceof HttpExceptionInterface) {
            return __('This PDF or lesson is no longer available. Close this dialog and try again.');
        }
        report($exception);

        return $fallback;
    }

    public function uploadPdf(): void
    {
        abort_unless($this->pdfModalOpen && $this->pdfRecordKey && $this->pdfOperationToken, 422);
        $index = collect($this->records)->search(fn ($record) => $record['key'] === $this->pdfRecordKey);
        abort_if($index === false, 409);
        $record = $this->records[$index];
        $operationKey = 'media:upload-pdf:'.$this->pdfRecordKey;
        $this->operationPending($operationKey, $this->operationMetadata('upload-pdf', ['record_key' => $this->pdfRecordKey], 'media'));
        $this->resetValidation('pdfUpload');
        try {
            $this->guardCanonicalReplacement('upload-pdf', ['record_id' => $record['id']], 'pdf');
            $this->validate(['pdfUpload' => ['required', 'file', 'mimetypes:application/pdf', 'max:10240'], 'pdfLinkText' => ['nullable', 'string', 'max:100000']]);
            $document = $this->editorContext()->performMedia($this->editorRootId, 'upload-pdf', [
                'upload' => $this->pdfUpload, 'record_id' => $record['id'],
                'revision' => $this->revisions['record:'.$record['id']] ?? $this->revisions['root'] ?? '',
            ]);
            $this->dispatch('oceanix:insert-pdf', model: 'records.'.$index.'.content_markdown', recordKey: $this->pdfRecordKey, token: $this->pdfOperationToken, reference: $document['reference'], label: $this->pdfLinkText === '' ? $document['name'] : $this->pdfLinkText);
            $this->operationSucceeded($operationKey);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->addError('pdfUpload', $message);
            $this->operationFailed($operationKey, $message);
        } catch (AuthorizationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $message = __('The PDF upload could not be completed. Try again.');
            $this->addError('pdfUpload', $message);
            $this->operationFailed($operationKey, $message);
        }
    }

    public function uploadContentImage(): void
    {
        abort_if($this->imageLibraryRecordKey === null, 422);
        $operationKey = 'media:upload-image:'.$this->imageLibraryRecordKey;
        $metadata = $this->operationMetadata('upload-image', ['record_key' => $this->imageLibraryRecordKey], 'media');
        $this->operationPending($operationKey, $metadata);
        $this->resetValidation('contentImageUpload');
        try {
            $this->guardCanonicalReplacement('upload-image', ['record_key' => $this->imageLibraryRecordKey], 'image');
            $this->validate(['contentImageUpload' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240']]);
            $image = $this->editorContext()->performMedia($this->editorRootId, 'upload-image', ['upload' => $this->contentImageUpload]);
            $this->reset('contentImageUpload');
            $this->insertContentImage($image);
            $this->operationSucceeded($operationKey);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? __('The image upload could not be completed.');
            $this->addError('contentImageUpload', $message);
            $this->operationFailed($operationKey, $message);
        } catch (AuthorizationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->operationFailed($operationKey, __('The image upload could not be completed. Try again.'));
        }
    }

    public function selectContentImage(int $imageId): void
    {
        abort_if($this->imageLibraryRecordKey === null, 422);
        $operationKey = 'media:select-image:'.$this->imageLibraryRecordKey;
        $metadata = $this->operationMetadata('select-image', ['record_key' => $this->imageLibraryRecordKey], 'media');
        $this->operationPending($operationKey, $metadata);
        try {
            $this->guardCanonicalReplacement('select-image', ['record_key' => $this->imageLibraryRecordKey], 'image');
            $this->insertContentImage($this->editorContext()->performMedia($this->editorRootId, 'select-image', ['image_id' => $imageId]));
            $this->operationSucceeded($operationKey);
        } catch (ValidationException $exception) {
            $this->operationFailed($operationKey, collect($exception->errors())->flatten()->first() ?? __('The image could not be selected.'));
        } catch (AuthorizationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->operationFailed($operationKey, __('The image could not be selected. Try again.'));
        }
    }

    public function confirmPublish(): void
    {
        try {
            $this->guardPublishable();
            $this->refreshPublicationProjection();
        } catch (ValidationException $exception) {
            $this->addError('publish', collect($exception->errors())->flatten()->first() ?? __('Save all changes before publishing.'));

            return;
        } catch (CoursePublicationException $exception) {
            $this->publicationProblems = $exception->problems;
            $this->addError('publish', $exception->problems[0] ?? $exception->getMessage());

            return;
        } catch (LogicException $exception) {
            $this->addError('publish', $exception->getMessage());

            return;
        }
        $this->assignmentUpdateMode = 'keep_existing';
        $this->restartInProgress = false;
        $this->confirmingPublish = true;
    }

    public function publish(): void
    {
        try {
            $this->guardPublishable();
            $this->editorContext()->publish($this->editorRootId, [
                'restart_in_progress' => $this->restartInProgress,
                'replace_open' => $this->assignmentUpdateMode === 'replace_open',
            ]);
        } catch (CoursePublicationException $exception) {
            $this->publicationProblems = $exception->problems;
            foreach ($exception->problems as $problem) {
                $this->addError('publish', $problem);
            }

            return;
        } catch (ValidationException $exception) {
            $this->addError('publish', collect($exception->errors())->flatten()->first() ?? __('Save all changes before publishing.'));

            return;
        } catch (LogicException $exception) {
            $this->addError('publish', $exception->getMessage());

            return;
        }
        $this->confirmingPublish = false;
        session()->flash('status', match ($this->fixedContextName()) {
            'company-course' => __('ui.version_published', ['number' => $this->versionForm['version_number'] ?? '']),
            'shared-course' => __('Version :number published', ['number' => $this->versionForm['version_number'] ?? '']),
            default => __('Shared module published.'),
        });
        $this->redirect($this->closeUrl(), navigate: true);
    }

    protected function applySnapshot(EditorSnapshot $snapshot): void
    {
        abort_unless(hash_equals($this->fixedContextName(), $snapshot->context), 500);
        $this->editorContextName = $this->fixedContextName();
        $this->courseForm = $snapshot->course ?? [];
        $this->versionForm = $snapshot->version;
        $this->records = $snapshot->records;
        $this->revisions = $snapshot->revisions;
        $this->capabilities = $snapshot->capabilities->toArray();
        $this->compositionMode = $snapshot->composition;
        $this->editorCloseUrl = $snapshot->closeUrl;
        $this->preservedRecords = $snapshot->preservedRecords;
        $this->previewPanel = $snapshot->previewPanel;
        $recordKeys = array_column($this->records, 'key');
        $this->expanded = array_values(array_intersect($this->expanded, $recordKeys));
        if ($this->expanded === [] && $recordKeys !== []) {
            $this->expanded = [$recordKeys[0]];
        }
    }

    private function captureStagedState(): EditorStagedState
    {
        return $this->stagedStateRebaser->capture(
            $this->courseForm === [] ? null : $this->courseForm,
            $this->versionForm,
            $this->records,
            $this->dirtyContentKeys,
            $this->getErrorBag()->getMessages(),
            $this->localGeneration,
            $this->editorDirty,
            $this->saveState,
            $this->errorKind,
            $this->saveError,
            $this->focusInvalidField,
        );
    }

    /** @param list<string> $removedRecordKeys */
    private function applyRebasedSnapshot(EditorSnapshot $snapshot, EditorStagedState $staged, array $removedRecordKeys = []): void
    {
        $result = $this->stagedStateRebaser->rebase(
            $snapshot->course,
            $snapshot->version,
            $snapshot->records,
            $staged,
            $removedRecordKeys,
        );

        $this->applySnapshot($snapshot);
        $this->courseForm = $result->course ?? [];
        $this->versionForm = $result->version;
        $this->records = $result->records;
        $this->dirtyContentKeys = $result->dirtyContentKeys;
        $this->localGeneration = $result->generation;
        $this->editorDirty = $result->dirty;
        $this->saveState = $result->saveState;
        $this->errorKind = $result->errorKind;
        $this->saveError = $result->saveError;
        $this->focusInvalidField = $result->focusInvalidField;
        $this->resetErrorBag();
        foreach ($result->validationMessages as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError($field, $message);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<string>
     */
    private function removedRecordKeys(string $operation, array $payload, array $before, array $after): array
    {
        if (! in_array($operation, ['remove-record', 'remove-question', 'remove-option'], true)) {
            return [];
        }

        return array_values(array_diff($this->structuralKeys($before), $this->structuralKeys($after)));
    }

    /** @param list<array<string, mixed>> $records @return list<string> */
    private function structuralKeys(array $records): array
    {
        $keys = [];
        foreach ($records as $record) {
            $keys[] = (string) $record['key'];
            foreach ($record['questions'] ?? [] as $question) {
                $keys[] = (string) $question['key'];
                foreach ($question['options'] ?? [] as $option) {
                    $keys[] = (string) $option['key'];
                }
            }
        }

        return $keys;
    }

    /** @param array<string, mixed> $payload */
    private function runStructure(string $operation, array $payload = [], ?string $validationField = null): bool
    {
        $key = 'structure:'.$operation.':'.($payload['record_id'] ?? $payload['question_id'] ?? 'root');
        $beforeRecords = $this->records;
        $expectedRevision = (string) ($payload['_expected_revision'] ?? $this->revisions['root'] ?? '');
        $recordRevision = (string) ($payload['_record_revision'] ?? (isset($payload['record_id']) ? ($this->revisions['record:'.$payload['record_id']] ?? $this->revisions['root'] ?? '') : ($this->revisions['root'] ?? '')));
        $this->operationPending($key, $this->operationMetadata($operation, $payload, 'structure'));
        try {
            $staged = $this->captureStagedState();
            $this->guardCanonicalReplacement($operation, $payload, 'structure');
            $snapshot = $this->editorContext()->performStructure($this->editorRootId, $operation, [
                ...$payload,
                'expected_revision' => $expectedRevision,
                'record_revision' => $recordRevision,
                'revisions' => $this->revisions,
            ]);
            $addedFocusKey = $this->addedFocusTarget($operation, $payload, $beforeRecords, $snapshot->records);
            $removedRecordKeys = $this->removedRecordKeys($operation, $payload, $beforeRecords, $snapshot->records);
            $this->applyRebasedSnapshot($snapshot, $staged, $removedRecordKeys);
            $this->operationSucceeded($key);
            $this->dispatch('editor-operation-finished', operation: $key, state: 'succeeded', droppedRecordKeys: $removedRecordKeys);
            if ($addedFocusKey !== null) {
                if (! str_starts_with($addedFocusKey, 'question:') && ! str_starts_with($addedFocusKey, 'option:') && ! in_array($addedFocusKey, $this->expanded, true)) {
                    $this->expanded[] = $addedFocusKey;
                }
                $this->restoreEditorFocus($addedFocusKey, 'first-authored-field');
            } elseif (isset($payload['_focus_record_key'], $payload['_focus_action'])) {
                $this->restoreEditorFocus($payload['_focus_record_key'], $payload['_focus_action']);
            }

            $this->refreshPublicationProjection();

            return true;
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? __('The operation could not be completed.');
            $conflict = array_key_exists('revision', $exception->errors());
            if ($conflict) {
                $this->saveState = 'conflict';
                $this->errorKind = 'conflict';
                $this->saveError = $message;
            }
            if ($validationField !== null) {
                $this->addError($validationField, $message);
            }
            $this->operationFailed($key, $message, ['retry_available' => false, 'retry_guidance' => $conflict ? __('Reload the latest draft only after copying or deliberately discarding your local values.') : __('Resolve the editor state, then use the original action again.')]);

            return false;
        } catch (AuthorizationException $exception) {
            $this->markPermissionLost();
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 403) {
                $this->markPermissionLost();
                throw $exception;
            }
            report($exception);
            $this->operationFailed($key, __('The operation could not be completed. Try again.'), $this->rememberOperationRetry($key, 'structure', $operation, $payload, $expectedRevision, $recordRevision));

            return false;
        } catch (Throwable $exception) {
            report($exception);
            $this->operationFailed($key, __('The operation could not be completed. Try again.'), $this->rememberOperationRetry($key, 'structure', $operation, $payload, $expectedRevision, $recordRevision));

            if ($validationField !== null) {
                $this->newModuleError = __('The shared module could not be created. Check the course draft and try again.');
            }

            return false;
        }
    }

    /** @param array<string, mixed> $payload */
    private function runMedia(string $operation, array $payload): bool
    {
        $key = 'media:'.$operation.':'.$payload['record_id'];
        $expectedRevision = (string) ($payload['_expected_revision'] ?? $this->revisions['root'] ?? '');
        $recordRevision = (string) ($payload['_record_revision'] ?? $this->revisions['record:'.$payload['record_id']] ?? $this->revisions['root'] ?? '');
        $this->operationPending($key, $this->operationMetadata($operation, $payload, 'media'));
        try {
            $staged = $this->captureStagedState();
            $this->guardCanonicalReplacement($operation, $payload, 'media');
            $this->editorContext()->performMedia($this->editorRootId, $operation, [
                ...$payload,
                'expected_revision' => $expectedRevision,
                'record_revision' => $recordRevision,
            ]);
            $this->applyRebasedSnapshot($this->editorContext()->open($this->editorRootId), $staged);
            $this->operationSucceeded($key);
            $this->dispatch('editor-operation-finished', operation: $key, state: 'succeeded');

            return true;
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? __('The media change could not be completed.');
            $conflict = array_key_exists('revision', $exception->errors());
            if ($conflict) {
                $this->saveState = 'conflict';
                $this->errorKind = 'conflict';
                $this->saveError = $message;
            }
            $this->operationFailed($key, $message, ['retry_available' => false, 'retry_guidance' => $conflict ? __('Reload the latest draft only after copying or deliberately discarding your local values.') : __('Resolve the editor state, then use the original action again.')]);

            return false;
        } catch (AuthorizationException $exception) {
            $this->markPermissionLost();
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 403) {
                $this->markPermissionLost();
                throw $exception;
            }
            report($exception);
            $this->operationFailed($key, __('The media change could not be completed. Try again.'), $this->rememberOperationRetry($key, 'media', $operation, $payload, $expectedRevision, $recordRevision));

            return false;
        } catch (Throwable $exception) {
            report($exception);
            $this->operationFailed($key, __('The media change could not be completed. Try again.'), $this->rememberOperationRetry($key, 'media', $operation, $payload, $expectedRevision, $recordRevision));

            return false;
        }
    }

    private function loadVideoLibrary(): void
    {
        $this->videoLibraryError = null;
        try {
            $this->videoLibraryItems = $this->editorContext()->videoLibrary(
                $this->editorRootId,
                $this->videoLibraryRecordId ?? abort(404),
                $this->videoLibrarySearch,
            );
        } catch (VideoProviderException) {
            $this->videoLibraryItems = [];
            $this->videoLibraryError = __('The video library could not be loaded. Try again.');
        }
    }

    /** @param array{id: int, name: string, url: string} $image */
    private function insertContentImage(array $image): void
    {
        $recordIndex = collect($this->records)->search(fn (array $record): bool => $record['key'] === $this->imageLibraryRecordKey);
        abort_if($recordIndex === false, 409);
        $this->dispatch('oceanix:insert-image',
            model: 'records.'.$recordIndex.'.content_markdown',
            url: $image['url'],
            alt: pathinfo($image['name'], PATHINFO_FILENAME),
        );
        $this->imageLibraryOpen = false;
    }

    /** @param array<string, mixed> $payload */
    private function guardCanonicalReplacement(string $operation, array $payload, string $kind): void
    {
        if (in_array($this->saveState, ['saving', 'conflict', 'unknown-outcome', 'permission-lost'], true)) {
            throw ValidationException::withMessages(['structure' => __('Recover the editor state before modifying structure or media.')]);
        }
        if ($this->hasConflictingActiveUpload($operation, $payload, $kind)) {
            throw ValidationException::withMessages(['structure' => __('Wait for active uploads to finish before modifying structure or media.')]);
        }
    }

    private function guardPublishable(): void
    {
        if ($this->editorDirty || in_array($this->saveState, ['saving', 'validation-error', 'conflict', 'network-error', 'unknown-outcome', 'permission-lost'], true)) {
            throw ValidationException::withMessages(['publish' => __('Save or recover your authored changes before publishing.')]);
        }
        if ($this->uploadInProgress) {
            throw ValidationException::withMessages(['publish' => __('Wait for active uploads to finish before publishing.')]);
        }
    }

    /** @param array<string, int> $payload */
    private function prepareDestructive(string $action, array $payload, string $title, string $consequence, string $submitLabel, ?EditorSnapshot $snapshot = null): void
    {
        $kind = $action === 'remove-video' ? 'media' : 'structure';
        $this->guardCanonicalReplacement($action, $payload, $kind);
        $snapshot ??= $this->editorContext()->open($this->editorRootId);
        $this->destructiveAction = $action;
        $this->destructivePayload = $payload;
        $this->destructiveTitle = $title;
        $this->destructiveConsequence = $consequence;
        $this->destructiveSubmitLabel = $submitLabel;
        $this->destructiveTargetKey = match (true) {
            isset($payload['option_id']) => 'option:'.$payload['option_id'],
            isset($payload['question_id']) => 'question:'.$payload['question_id'],
            isset($payload['record_id']) => (string) (collect([...$this->records, ...$this->preservedRecords])->firstWhere('id', $payload['record_id'])['key'] ?? 'record:'.$payload['record_id']),
            default => 'editor',
        };
        $recordId = isset($payload['record_id']) ? (int) $payload['record_id'] : null;
        $this->destructiveExpectedRevision = $snapshot->revisions['root'] ?? '';
        $this->destructiveExpectedRecordRevision = $recordId === null
            ? $this->destructiveExpectedRevision
            : ($snapshot->revisions['record:'.$recordId] ?? $this->destructiveExpectedRevision);
        $this->confirmingDestructive = true;
    }

    /** @return array{EditorSnapshot, array<string, mixed>}|null */
    private function canonicalRecord(int $recordId, bool $preserved = false): ?array
    {
        $snapshot = $this->editorContext()->open($this->editorRootId);
        $heldRootRevision = (string) ($this->revisions['root'] ?? '');
        $heldRecordRevision = (string) ($this->revisions['record:'.$recordId] ?? $heldRootRevision);
        $freshRootRevision = (string) ($snapshot->revisions['root'] ?? '');
        $freshRecordRevision = (string) ($snapshot->revisions['record:'.$recordId] ?? $freshRootRevision);

        if ($heldRootRevision === '' || $heldRecordRevision === '' || $heldRootRevision !== $freshRootRevision || $heldRecordRevision !== $freshRecordRevision) {
            $this->saveState = 'conflict';
            $this->errorKind = 'conflict';
            $this->saveError = __('The saved draft changed before this action. Reload the latest draft before trying again.');
            $this->dispatch('editor-operation-finished', operation: 'confirmation:'.$recordId, state: 'failed', editorState: 'conflict', errorKind: 'conflict');

            return null;
        }

        $record = collect($preserved ? $snapshot->preservedRecords : $snapshot->records)->firstWhere('id', $recordId) ?? abort(404);

        return [$snapshot, $record];
    }

    /** @return list<int> */
    private function confirmedCompositionIds(int $removedRecordId): array
    {
        $snapshot = $this->editorContext()->open($this->editorRootId);
        abort_unless(collect($snapshot->preservedRecords)->contains(fn (array $record): bool => (int) $record['id'] === $removedRecordId), 404);

        return collect($snapshot->preservedRecords)
            ->pluck('id')
            ->reject(fn ($id): bool => (int) $id === $removedRecordId)
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function guardUploadRequest(int $recordId, ?string $retryToken = null): void
    {
        if (in_array($this->saveState, ['saving', 'conflict', 'unknown-outcome', 'permission-lost'], true)) {
            throw ValidationException::withMessages(['media' => __('Recover the editor state before modifying structure or media.')]);
        }
        $sameRecordUploadActive = collect($this->activeUploads)->contains(
            fn (array $upload, string $token): bool => $token !== $retryToken
                && (int) ($upload['record_id'] ?? 0) === $recordId
                && in_array($upload['state'] ?? null, ['uploading', 'processing', 'retrying'], true),
        );
        if ($sameRecordUploadActive) {
            throw ValidationException::withMessages(['media' => __('Wait for this record’s active upload to finish before starting another.')]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function hasConflictingActiveUpload(string $operation, array $payload, string $kind): bool
    {
        if (! $this->uploadInProgress || $kind === 'image') {
            return false;
        }

        $recordId = isset($payload['record_id']) ? (int) $payload['record_id'] : null;
        if ($recordId === null) {
            return false;
        }

        if ($kind === 'structure' && $operation !== 'remove-record') {
            return false;
        }

        return collect($this->activeUploads)->contains(
            fn (array $upload): bool => (int) ($upload['record_id'] ?? 0) === $recordId
                && in_array($upload['state'] ?? null, ['uploading', 'processing', 'retrying'], true),
        );
    }

    /** @return array<string, mixed> */
    private function record(int $recordId): array
    {
        return collect($this->records)->firstWhere('id', $recordId) ?? abort(404);
    }

    private function resolvedRecordId(int $idOrIndex): int
    {
        $byId = collect($this->records)->firstWhere('id', $idOrIndex);

        return (int) ($byId['id'] ?? $this->records[$idOrIndex]['id'] ?? abort(404));
    }

    /** @param list<int> $ids @return list<int> */
    private function moved(array $ids, int $id, int $direction): array
    {
        $index = array_search($id, $ids, true);
        abort_if($index === false, 404);
        $target = $index + $direction;
        if ($target < 0 || $target >= count($ids)) {
            return $ids;
        }
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        return $ids;
    }

    /** @param list<array<string, mixed>> $before @param list<array<string, mixed>> $after */
    private function addedFocusTarget(string $operation, array $payload, array $before, array $after): ?string
    {
        if (in_array($operation, ['add-record', 'attach-existing-record', 'create-record'], true)) {
            $beforeKeys = array_column($before, 'key');
            $added = collect($after)->first(fn (array $record): bool => ! in_array($record['key'], $beforeKeys, true));

            return is_array($added) ? (string) $added['key'] : null;
        }

        $recordId = (int) ($payload['record_id'] ?? 0);
        $beforeRecord = collect($before)->firstWhere('id', $recordId);
        $afterRecord = collect($after)->firstWhere('id', $recordId);
        if (! is_array($beforeRecord) || ! is_array($afterRecord)) {
            return null;
        }

        if ($operation === 'add-question') {
            $beforeKeys = array_column($beforeRecord['questions'] ?? [], 'key');
            $added = collect($afterRecord['questions'] ?? [])->first(fn (array $question): bool => ! in_array($question['key'], $beforeKeys, true));

            return is_array($added) ? (string) $added['key'] : null;
        }

        if ($operation === 'add-option') {
            $questionId = (int) ($payload['question_id'] ?? 0);
            $beforeQuestion = collect($beforeRecord['questions'] ?? [])->firstWhere('id', $questionId);
            $afterQuestion = collect($afterRecord['questions'] ?? [])->firstWhere('id', $questionId);
            if (! is_array($beforeQuestion) || ! is_array($afterQuestion)) {
                return null;
            }
            $beforeKeys = array_column($beforeQuestion['options'] ?? [], 'key');
            $added = collect($afterQuestion['options'] ?? [])->first(fn (array $option): bool => ! in_array($option['key'], $beforeKeys, true));

            return is_array($added) ? (string) $added['key'] : null;
        }

        return null;
    }

    private function restoreEditorFocus(string $recordKey, string $action): void
    {
        $this->focusRecordKey = $recordKey;
        $this->focusAction = $action;
        $this->focusGeneration++;
        $this->dispatch('editor-restore-focus', recordKey: $recordKey, action: $action, generation: $this->focusGeneration);
    }

    /** @param array<string, mixed> $metadata */
    private function operationPending(string $key, array $metadata = []): void
    {
        unset($this->operationRetries[$key]);
        $this->operations[$key] = [...$metadata, 'key' => $key, 'state' => 'pending', 'error' => null];
    }

    private function operationSucceeded(string $key): void
    {
        unset($this->operationRetries[$key]);
        $this->operations[$key] = [...($this->operations[$key] ?? []), 'key' => $key, 'state' => 'succeeded', 'error' => null];
    }

    /** @param array{retry_token?: string, record_id?: int, retry_mode?: string} $retry */
    private function operationFailed(string $key, string $message, array $retry = []): void
    {
        $this->operations[$key] = [...($this->operations[$key] ?? []), 'key' => $key, 'state' => 'failed', 'error' => $message, ...$retry];
        $this->dispatch('editor-operation-finished', operation: $key, state: 'failed', editorState: $this->saveState, errorKind: $this->errorKind);
    }

    /** @param array<string, mixed> $payload @return array{retry_available: true, retry_guidance: string} */
    private function rememberOperationRetry(string $key, string $kind, string $operation, array $payload, string $expectedRevision, string $recordRevision): array
    {
        $this->operationRetries[$key] = [
            'kind' => $kind,
            'operation' => $operation,
            'payload' => [...$payload, '_expected_revision' => $expectedRevision, '_record_revision' => $recordRevision],
        ];

        return ['retry_available' => true, 'retry_guidance' => __('Retry this exact action for :target.', ['target' => $this->operations[$key]['target_label'] ?? __('this target')])];
    }

    private function refreshUploadInProgress(): void
    {
        $this->uploadInProgress = collect($this->activeUploads)->contains(
            fn (array $upload): bool => in_array($upload['state'] ?? null, ['uploading', 'processing', 'retrying'], true),
        );
    }

    /** @return array<string, mixed> */
    private function uploadIdentity(int $recordId, ?string $token): array
    {
        abort_if($token === null, 422);
        $identity = $this->activeUploads[$token] ?? null;
        abort_unless(is_array($identity) && (int) $identity['record_id'] === $recordId, 404);

        return $identity;
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function operationMetadata(string $operation, array $payload, string $kind): array
    {
        $record = null;
        if (isset($payload['record_id'])) {
            $record = collect($this->records)->firstWhere('id', (int) $payload['record_id']);
        } elseif (isset($payload['record_key'])) {
            $record = collect($this->records)->firstWhere('key', (string) $payload['record_key']);
        }
        $targetKey = (string) ($payload['_focus_record_key'] ?? $record['key'] ?? ($kind.':root'));
        if (! isset($payload['_focus_record_key']) && isset($payload['option_id'])) {
            $targetKey = 'option:'.$payload['option_id'];
        } elseif (! isset($payload['_focus_record_key']) && isset($payload['question_id'])) {
            $targetKey = 'question:'.$payload['question_id'];
        }
        $targetLabel = $this->operationTargetLabel($targetKey, $record);
        $actionRecord = $record ?? collect($this->records)->firstWhere('key', $targetKey);
        $actionLabel = $this->operationActionLabel($operation, is_array($actionRecord) ? $actionRecord : null);

        return [
            'kind' => $kind,
            'action' => $operation,
            'target_key' => $targetKey,
            'target_label' => $targetLabel,
            'action_label' => $actionLabel,
            'pending_text' => __(':action in progress for :target…', ['action' => $actionLabel, 'target' => $targetLabel]),
            'success_text' => __(':action completed for :target.', ['action' => $actionLabel, 'target' => $targetLabel]),
            'failure_text' => __(':action failed for :target.', ['action' => $actionLabel, 'target' => $targetLabel]),
        ];
    }

    /** @param array<string, mixed>|null $record */
    private function operationTargetLabel(string $targetKey, ?array $record): string
    {
        $targetRecord = collect($this->records)->firstWhere('key', $targetKey);
        if (is_array($targetRecord)) {
            return Str::limit(trim((string) $targetRecord['title']) ?: __('Untitled record'), 120);
        }
        if (str_starts_with($targetKey, 'question:')) {
            $questionId = (int) Str::after($targetKey, 'question:');
            foreach ($this->records as $candidate) {
                $question = collect($candidate['questions'] ?? [])->firstWhere('id', $questionId);
                if (is_array($question)) {
                    return Str::limit(trim((string) $question['prompt']) ?: __('Untitled question'), 120);
                }
            }
        }
        if (str_starts_with($targetKey, 'option:')) {
            $optionId = (int) Str::after($targetKey, 'option:');
            foreach ($this->records as $candidate) {
                foreach ($candidate['questions'] ?? [] as $question) {
                    $option = collect($question['options'] ?? [])->firstWhere('id', $optionId);
                    if (is_array($option)) {
                        return Str::limit(trim((string) $option['text']) ?: __('Untitled answer'), 120);
                    }
                }
            }
        }

        return Str::limit((string) ($record['title'] ?? ($this->courseForm['title'] ?? __('Editor draft'))), 120);
    }

    /** @param array<string, mixed>|null $record */
    private function operationActionLabel(string $operation, ?array $record): string
    {
        $recordType = ($record['type'] ?? null) === 'module' ? __('module') : __('lesson');

        return match ($operation) {
            'add-record' => __('Add lesson'),
            'remove-record' => __('Remove :type', ['type' => $recordType]),
            'reorder-records' => __('Move :type', ['type' => $recordType]),
            'add-question' => __('Add question'),
            'remove-question' => __('Remove question'),
            'reorder-questions' => __('Move question'),
            'add-option' => __('Add answer'),
            'remove-option' => __('Remove answer'),
            'reorder-options' => __('Move answer'),
            'change-composition' => __('Update module composition'),
            'attach-existing-record' => __('Add shared module'),
            'create-record' => __('Create shared module'),
            'upload' => __('Upload video'),
            'attach' => __('Attach video'),
            'remove' => __('Remove video'),
            'upload-image' => __('Upload image'),
            'select-image' => __('Insert image'),
            'reuse-pdf' => __('Reuse PDF'),
            'upload-pdf' => __('Upload PDF'),
            default => Str::headline($operation),
        };
    }

    private function safeUploadFilename(?string $filename): ?string
    {
        $filename = trim(basename((string) $filename));

        return $filename === '' ? null : Str::limit($filename, 180, '…');
    }

    private function setUploadRowState(string $token, string $state, ?string $failureKind = null): void
    {
        if (! isset($this->uploadRows[$token])) {
            return;
        }
        $this->uploadRows[$token]['state'] = $state;
        $this->uploadRows[$token]['failure_kind'] = $failureKind;
    }

    private function mapValidationField(string $field): string
    {
        if (str_starts_with($field, 'course.')) {
            return 'courseForm.'.Str::after($field, 'course.');
        }
        if (str_starts_with($field, 'version.')) {
            return 'versionForm.'.Str::after($field, 'version.');
        }
        if (str_starts_with($field, 'modules.')) {
            return 'records.'.Str::after($field, 'modules.');
        }
        if (str_starts_with($field, 'records.')) {
            return str_ends_with($field, '.options') ? $field.'.0.is_correct' : $field;
        }
        if (str_starts_with($field, 'questions')) {
            $mapped = 'records.0.'.$field;

            return str_ends_with($mapped, '.options') ? $mapped.'.0.is_correct' : $mapped;
        }
        if (in_array($field, ['title', 'description', 'content_markdown', 'minimum_watch_percentage', 'passing_score'], true)) {
            return 'records.0.'.$field;
        }
        if (in_array($field, ['modules', 'records'], true)) {
            return 'records.0.title';
        }

        return $field;
    }

    private function markPermissionLost(?int $generation = null): void
    {
        $this->saveState = 'permission-lost';
        $this->errorKind = 'permission';
        $this->saveError = __('You no longer have permission to edit this draft. Your local values remain available to copy.');
        $this->confirmingPublish = false;
        $this->confirmingReload = false;
        $this->dispatch('editor-save-finished', state: $this->saveState, generation: $generation ?? $this->localGeneration);
    }

    private function isAuthoredProperty(string $property): bool
    {
        return preg_match('/^(courseForm\.(code|title|description)|versionForm\.(title|description)|records\.\d+\.(title|description|content_markdown|is_required|minimum_watch_percentage|passing_score)|records\.\d+\.questions\.\d+\.(prompt|type|max_attempts)|records\.\d+\.questions\.\d+\.options\.\d+\.(text|is_correct))$/', $property) === 1;
    }

    private function closeUrl(): string
    {
        return $this->editorCloseUrl !== '' ? $this->editorCloseUrl : url('/');
    }

    private function refreshSupportingProjections(): void
    {
        $this->availableModuleGroups = $this->editorContext()->availableRecords($this->editorRootId, $this->moduleSearch);
        $this->refreshPublicationProjection();
    }

    private function refreshPublicationProjection(): void
    {
        if (! ($this->capabilities['publish'] ?? false)) {
            $this->publicationImpact = [];
            $this->publicationProblems = [];
            $this->impact = [];
            $this->publicationConfirmation = [
                'title' => '',
                'body' => '',
                'submit_label' => '',
                'problems_title' => '',
                'assignment_mode' => false,
                'restart_in_progress' => false,
                'restart_description' => '',
            ];

            return;
        }
        $this->publicationImpact = $this->editorContext()->publicationImpact($this->editorRootId);
        $this->publicationProblems = $this->editorContext()->publicationProblems($this->editorRootId);
        $this->publicationConfirmation = $this->editorContext()->publicationConfirmation($this->editorRootId);
        $this->impact = $this->publicationImpact;
    }

    private function fixedContextName(): string
    {
        return $this->editorContext()->name();
    }
}
