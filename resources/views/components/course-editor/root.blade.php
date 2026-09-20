@props([
    'context',
    'editorRootId',
    'editorCloseUrl',
    'courseForm' => [],
    'versionForm' => [],
    'records' => [],
    'revisions' => [],
    'capabilities' => [],
    'compositionMode' => 'direct',
    'expanded' => [],
    'editorDirty' => false,
    'saveState' => 'clean',
    'saveError' => null,
    'errorKind' => null,
    'localGeneration' => 0,
    'savedAt' => null,
    'uploadInProgress' => false,
    'activeUploads' => [],
    'uploadRows' => [],
    'operations' => [],
    'preservedRecords' => [],
    'videoLibraryOpen' => false,
    'videoLibraryRecordId' => null,
    'videoLibraryRecordKey' => null,
    'videoLibraryItems' => [],
    'videoLibrarySearch' => '',
    'videoLibraryError' => null,
    'moduleSearch' => '',
    'selectedModuleId' => null,
    'availableModuleGroups' => [],
    'newModuleModalOpen' => false,
    'newModuleForm' => ['code' => '', 'title' => '', 'description' => ''],
    'newModuleError' => null,
    'publicationImpact' => [],
    'publicationProblems' => [],
    'publicationConfirmation' => [
        'title' => '',
        'body' => '',
        'submit_label' => '',
        'problems_title' => '',
        'assignment_mode' => false,
        'restart_in_progress' => false,
        'restart_description' => '',
    ],
    'confirmingPublish' => false,
    'confirmingReload' => false,
    'confirmingDestructive' => false,
    'destructiveAction' => '',
    'destructiveTitle' => '',
    'destructiveConsequence' => '',
    'destructiveSubmitLabel' => '',
    'destructiveTargetKey' => '',
    'confirmingModuleRemoval' => false,
    'moduleRemovalRecordKey' => null,
    'moduleRemovalTitle' => '',
    'moduleRemovalReason' => '',
    'assignmentUpdateMode' => 'keep_existing',
    'restartInProgress' => false,
    'previewPanel' => null,
    'imageLibraryOpen' => false,
    'imageLibraryRecordKey' => null,
    'contentImages' => [],
    'pdfRecordKey' => null,
    'pdfLibrary' => [],
    'pdfLibraryError' => null,
    'pdfLibraryNotice' => '',
    'pdfSearch' => '',
    'pdfArchiveConfirmation' => null,
])

@php
    $isCourse = $context !== 'shared-module';
    $isCompany = $context === 'company-course';
    $title = $isCourse ? ($courseForm['title'] ?? __('Course editor')) : ($records[0]['title'] ?? __('Module editor'));
    $kicker = match ($context) {
        'company-course' => __('ui.draft_version', ['number' => $versionForm['version_number'] ?? '']),
        'shared-course' => __('Shared course draft'),
        default => __('Shared module draft'),
    };
    $description = $isCourse
        ? __('Edit course details, content, and assessments through one explicit Save.')
        : __('Edit module content and its assessment through one explicit Save.');
    $networkSaveError = __("Changes weren't saved. Check your connection and try again.");
    $contextLabel = match ($context) {
        'company-course' => __('Company course editor'),
        'shared-course' => __('Shared course editor'),
        default => __('Shared module editor'),
    };
    $videoLibraryUpload = collect($activeUploads)->first(
        fn (array $upload): bool => (int) ($upload['record_id'] ?? 0) === (int) $videoLibraryRecordId,
    );
    $actionsLocked = $saveState === 'permission-lost';
@endphp

<div
    class="admin-page min-w-0 max-w-full space-y-7"
    style="padding-bottom: calc(var(--editor-save-bar-height, 8rem) + 1rem);"
    data-editor-root
    data-editor-context="{{ $context }}"
    data-editor-actions-locked="{{ $actionsLocked ? 'true' : 'false' }}"
    x-bind:data-editor-actions-locked="state === 'permission-lost' ? 'true' : 'false'"
    data-editor-hydration-state="loading"
    x-bind:data-editor-hydration-state="ready ? 'ready' : 'loading'"
    data-editor-dirty="{{ $editorDirty ? 'true' : 'false' }}"
    x-bind:data-editor-dirty="dirty ? 'true' : 'false'"
    aria-label="{{ $contextLabel }}"
    x-data="courseEditorState({
        state: @js($saveState),
        dirty: @js($editorDirty),
        uploadInProgress: @js($uploadInProgress),
        localGeneration: @js($localGeneration),
        messages: @js([
            'loading' => __('Loading the editor before :action.'),
            'permission' => __('Permission was removed. :action is unavailable; copy any local values you need.'),
            'saving' => __('Wait for Save to finish before :action.'),
            'dirty' => __('Save or recover your authored changes before :action.'),
            'upload' => __('Wait for the conflicting upload to finish before :action.'),
            'operation' => __('Wait for the current editor operation to finish before :action.'),
            'failed' => __('The response for :action on :target was lost. Automatic retry is unavailable because the outcome is unknown. Check the target, then use the original action only if it is still needed.'),
            'confirmationFailed' => __('Confirmation for :action on :target could not be opened. No change was applied; try the original action again.'),
            'denied' => __('Permission was removed. :action on :target was not applied. Your local values remain available to copy.'),
            'pending' => __(':action in progress for :target…'),
        ]),
    })"
    x-on:input.capture="if ($event.isTrusted && $event.target.closest('[data-editor-field]')) markDirty($event)"
    x-on:change.capture="if ($event.isTrusted && $event.target.closest('[data-editor-field]')) markDirty($event)"
    x-on:oceanix:pdf-inserted="markDirty($event); $wire.pdfModalOpen = false"
    x-on:click.capture="if (handleOperationalClick($event)) rememberFocus($event)"
    x-on:editor-saved.window="finishSave($event.detail)"
    x-on:editor-save-finished.window="if ($event.detail.state !== 'saved') finishSave($event.detail)"
    x-on:editor-operation-finished.window="finishOperation($event.detail)"
    x-on:oceanix:client-operation-started="beginExternalOperation($event.detail)"
    x-on:oceanix:client-operation-finished="finishExternalOperation($event.detail)"
    x-on:editor-draft-reloaded.window="reloadDraft()"
    x-on:editor-restore-focus.window="restoreFocus($event.detail.recordKey, $event.detail.action, $event.detail.generation)"
    x-effect="state; dirty; ready; uploadInProgress; synchronizeOperationalControls(); $dispatch('oceanix:editor-state-changed', { state, dirty }); if (state === 'permission-lost') disableOperationalControls(); if ($wire.focusInvalidGeneration > 0) restoreInvalidFocus($wire.focusInvalidField); if ($wire.focusGeneration > 0) restoreFocus($wire.focusRecordKey, $wire.focusAction, $wire.focusGeneration)"
    x-on:oceanix-open-video-library.window="$wire.openEditorVideoLibrary($event.detail.model)"
    x-on:oceanix-open-image-library.window="$wire.openImageLibrary($event.detail.model)"
    x-on:oceanix-open-pdf.window="$wire.openPdfModal($event.detail.model, $event.detail.text, $event.detail.token).catch(() => {})"
    x-on:livewire:navigate.window="if (shouldWarn() && ! window.confirm({{ Js::from(__('You have unsaved changes. Leave without saving?')) }})) $event.preventDefault()">

    <p class="sr-only" data-editor-context-label>{{ $contextLabel }}</p>

    <x-page-hero :$kicker :$title :$description>
        @if ($context !== 'company-course')
            <span class="status-pill status-pill--accent">{{ __('Shared') }}</span>
        @endif
        <flux:button
            :href="$editorCloseUrl"
            wire:navigate
            data-editor-cancel
            aria-describedby="editor-upload-close-guidance"
            x-bind:aria-disabled="! ready || hasActiveUpload() ? 'true' : 'false'"
            x-on:click="blockClose($event)"
            variant="ghost">{{ __('Cancel') }}</flux:button>
    </x-page-hero>

    <div
        data-editor-loading
        x-show="! ready"
        role="status"
        aria-live="polite"
        class="detail-card space-y-3">
        <p class="font-bold">{{ __('Loading editor…') }}</p>
        <p class="text-sm text-[#5f6a71]">{{ __('Preparing the saved draft and locking actions until it is ready.') }}</p>
        <div class="grid gap-3 sm:grid-cols-2" aria-hidden="true">
            <span class="h-11 animate-pulse rounded-xl bg-[#e7ecef]"></span>
            <span class="h-11 animate-pulse rounded-xl bg-[#e7ecef]"></span>
        </div>
    </div>

    <p
        id="editor-operational-guidance"
        data-editor-operational-guidance
        x-cloak
        x-show="operationalGuidance"
        x-bind:data-action-detail="operationalGuidanceAction"
        x-bind:data-target-key="operationalGuidanceTarget"
        x-bind:data-guidance-severity="operationalGuidanceSeverity"
        x-bind:data-editor-guidance-error="operationalGuidanceSeverity === 'danger' ? 'true' : 'false'"
        x-bind:role="operationalGuidanceSeverity === 'danger' ? 'alert' : 'status'"
        x-bind:aria-live="operationalGuidanceSeverity === 'danger' ? 'assertive' : 'polite'"
        x-bind:class="operationalGuidanceSeverity === 'danger' ? 'border-red-200 bg-red-50 text-red-900' : 'border-amber-200 bg-amber-50 text-amber-900'"
        x-text="operationalGuidance"
        class="rounded-xl border px-4 py-3 text-sm font-semibold"></p>

    <div data-pdf-open-failure x-cloak x-show="pdfTransportFailure?.method === 'openPdfModal'" role="alert" class="rounded-xl border border-[var(--ds-status-negative)] p-3">
        <p>{{ __('The PDF request was interrupted. Results may be out of date. Your text and selection are preserved.') }}</p>
        <flux:button type="button" x-on:click="retryPdfRequest()" class="mt-2">{{ __('Try again') }}</flux:button>
    </div>

    <p
        id="editor-upload-close-guidance"
        data-editor-upload-close-guidance
        x-cloak
        x-show="hasActiveUpload()"
        role="status"
        aria-live="polite"
        class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
        {{ __('Wait for active uploads to finish before closing.') }}
    </p>

    @if ($isCourse)
        <x-courses.preview-link-panel :panel="$previewPanel" guard-editor :editor-state="$saveState" :editor-dirty="$editorDirty" />
    @endif

    <div
        data-editor-status
        data-editor-state="{{ $saveState }}"
        x-bind:data-editor-state="state"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        @class([
            'rounded-2xl border px-4 py-3 text-sm font-semibold',
            'border-emerald-200 bg-emerald-50 text-emerald-800' => in_array($saveState, ['clean', 'saved'], true),
            'border-amber-200 bg-amber-50 text-amber-800' => in_array($saveState, ['dirty', 'saving'], true),
            'border-red-200 bg-red-50 text-red-800' => in_array($saveState, ['validation-error', 'conflict', 'network-error', 'unknown-outcome', 'permission-lost'], true),
        ])>
        <span x-show="state === 'clean'">{{ __('All changes saved') }}</span>
        <span x-show="state === 'dirty'">{{ __('Unsaved changes') }}</span>
        <span x-show="state === 'saving'">{{ __('Saving changes…') }}</span>
        <span x-show="state === 'saved'">{{ $savedAt ? __('ui.saved_at', ['time' => $savedAt]) : __('All changes saved') }}</span>
        <span x-show="state === 'validation-error'">{{ __('Some changes need attention') }}</span>
        <span x-show="state === 'conflict'">{{ __('This draft changed in another session') }}</span>
        <span x-show="state === 'network-error'">{{ __("Changes weren't saved. Check your connection and try again.") }}</span>
        <span x-show="state === 'unknown-outcome'">{{ __('An editor action may have completed, but its response was lost. Check the target before recovering.') }}</span>
        <span x-show="state === 'permission-lost'">{{ __('Permission to edit was removed') }}</span>
    </div>

    <div
        data-editor-error
        data-error-kind="network"
        role="alert"
        x-cloak
        x-show="state === 'network-error' && {{ Js::from($errorKind !== 'network') }}">
        <flux:callout variant="danger" :heading="$networkSaveError" />
    </div>

    <div
        data-editor-error
        data-error-kind="unknown-outcome"
        role="alert"
        x-cloak
        x-show="state === 'unknown-outcome'">
        <flux:callout variant="danger" :heading="__('The action response was lost, so its outcome is unknown.')">
            <p class="mt-2 text-sm">{{ __('Your local values are still here. Check the target, then reload the latest draft only after copying or deliberately discarding them.') }}</p>
            <flux:button wire:click="confirmReloadLatest" data-editor-conflict-action="reload" variant="ghost" size="sm" class="mt-3">{{ __('Reload latest draft') }}</flux:button>
        </flux:callout>
    </div>

    @if ($saveError)
        <div data-editor-error data-error-kind="{{ $errorKind }}" role="alert">
            <flux:callout variant="danger" :heading="$saveError">
                @if ($errorKind === 'conflict')
                    <p class="mt-2 text-sm">{{ __('Your unsaved changes are still here. Reload only after copying or deliberately discarding them.') }}</p>
                    <flux:button wire:click="confirmReloadLatest" data-editor-conflict-action="reload" variant="ghost" size="sm" class="mt-3">{{ __('Reload latest draft') }}</flux:button>
                @elseif ($errorKind === 'network')
                    <flux:button wire:click="retrySave" variant="ghost" size="sm" class="mt-3">{{ __('Try save again') }}</flux:button>
                @endif
            </flux:callout>
        </div>
    @endif

    @foreach ($operations as $operationKey => $operation)
        @php
            $operationHeading = match ($operation['state'] ?? 'pending') {
                'succeeded' => $operation['success_text'] ?? __('Operation completed.'),
                'failed' => $operation['failure_text'] ?? __('Operation failed.'),
                default => $operation['pending_text'] ?? __('Operation in progress…'),
            };
            $retryHook = ($operation['kind'] ?? null) === 'media'
                ? match ($operation['action'] ?? '') {
                    'upload', 'upload-image' => 'upload',
                    'attach', 'select-image' => 'attach',
                    'remove' => 'remove',
                    default => 'retry-upload',
                }
                : match ($operation['action'] ?? '') {
                    'add-record', 'add-question', 'add-option', 'attach-existing-record', 'create-record' => 'add',
                    'remove-record', 'remove-question', 'remove-option' => 'remove',
                    'reorder-records', 'reorder-questions', 'reorder-options' => 'reorder',
                    default => 'change-composition',
                };
        @endphp
        <div
            role="{{ ($operation['state'] ?? null) === 'failed' ? 'alert' : 'status' }}"
            aria-live="polite"
            data-editor-operation
            data-operation-key="{{ $operationKey }}"
            data-operation-kind="{{ $operation['kind'] ?? 'operation' }}"
            data-operation-action="{{ $operation['action'] ?? '' }}"
            data-operation-state="{{ $operation['state'] ?? 'pending' }}"
            data-operation-retry-available="{{ ($operation['retry_available'] ?? false) ? 'true' : 'false' }}"
            data-operation-target-label="{{ $operation['target_label'] ?? '' }}"
            data-operation-message="{{ $operationHeading }}"
            data-record-key="{{ $operation['target_key'] ?? '' }}"
            wire:key="editor-operation-{{ sha1($operationKey) }}">
            <flux:callout
                :variant="($operation['state'] ?? null) === 'failed' ? 'danger' : (($operation['state'] ?? null) === 'succeeded' ? 'success' : 'warning')"
                :heading="$operationHeading">
                @if (($operation['state'] ?? null) === 'failed' && ($operation['error'] ?? null))
                    <p class="mt-2 text-sm" data-operation-guidance>{{ $operation['error'] }}</p>
                    @if ($operation['retry_available'] ?? false)
                        @if (($operation['kind'] ?? null) === 'media')
                            <flux:button
                                wire:click="retryOperation('{{ $operationKey }}')"
                                data-editor-media-action="{{ $retryHook }}"
                                data-editor-action-detail="retry-{{ $operation['action'] ?? 'operation' }}"
                                data-editor-target-key="{{ $operation['target_key'] ?? 'editor' }}"
                                variant="ghost"
                                size="sm"
                                class="mt-3">{{ __('Retry this action') }}</flux:button>
                        @else
                            <flux:button
                                wire:click="retryOperation('{{ $operationKey }}')"
                                data-editor-structure-action="{{ $retryHook }}"
                                data-editor-action-detail="retry-{{ $operation['action'] ?? 'operation' }}"
                                data-editor-target-key="{{ $operation['target_key'] ?? 'editor' }}"
                                variant="ghost"
                                size="sm"
                                class="mt-3">{{ __('Retry this action') }}</flux:button>
                        @endif
                        <p class="mt-2 text-xs" data-operation-retry-guidance>{{ $operation['retry_guidance'] }}</p>
                    @elseif ($operation['retry_token'] ?? null)
                        <p class="mt-2 text-xs" data-operation-upload-retry-guidance>{{ __('Use the retry control on the matching upload row.') }}</p>
                    @else
                        <p class="mt-2 text-xs" data-operation-retry-unavailable>{{ $operation['retry_guidance'] ?? __('Automatic retry is unavailable. Resolve the editor state, then use the original action again.') }}</p>
                    @endif
                @endif
            </flux:callout>
        </div>
    @endforeach

    @if ($uploadRows !== [])
        <section class="detail-card space-y-3" data-editor-upload-list aria-labelledby="editor-upload-list-heading">
            <h2 id="editor-upload-list-heading" class="detail-card-title">{{ __('Video uploads') }}</h2>
            @foreach ($uploadRows as $uploadToken => $upload)
                @php
                    $uploadState = $upload['state'] ?? 'uploading';
                    $uploadStateLabel = match ($uploadState) {
                        'processing' => __('Processing'),
                        'ready' => __('Ready'),
                        'failed' => __('Upload failed'),
                        default => __('Uploading'),
                    };
                @endphp
                <article
                    class="flex min-w-0 flex-col gap-3 rounded-xl border border-[#dde3e7] p-3 sm:flex-row sm:items-center sm:justify-between"
                    data-editor-upload
                    data-upload-token="{{ $uploadToken }}"
                    data-upload-state="{{ $uploadState }}"
                    data-record-key="{{ $upload['record_key'] }}"
                    wire:key="editor-upload-row-{{ $uploadToken }}">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold">{{ $upload['filename'] ?: $upload['title'] }}</p>
                        <p class="mt-1 text-xs text-[#707a80]">{{ $upload['title'] }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="text-sm font-semibold" role="{{ $uploadState === 'failed' ? 'alert' : 'status' }}" aria-live="{{ $uploadState === 'failed' ? 'assertive' : 'polite' }}">{{ $uploadStateLabel }}</span>
                        @if ($uploadState === 'failed')
                            @if (($upload['failure_kind'] ?? null) === 'transfer')
                                <flux:button
                                    x-on:click="$dispatch('oceanix:retry-video-upload', { recordId: {{ $upload['record_id'] }}, uploadToken: {{ Js::from($uploadToken) }} })"
                                    data-editor-upload-retry="transfer"
                                    data-editor-media-action="retry-upload"
                                    data-editor-action-detail="video-transfer-retry"
                                    data-upload-token="{{ $uploadToken }}"
                                    :disabled="$actionsLocked"
                                    variant="ghost"
                                    size="sm">{{ __('Try again') }}</flux:button>
                            @else
                                <flux:button
                                    wire:click="retryUpload({{ $upload['record_id'] }}, '{{ $uploadToken }}')"
                                    data-editor-upload-retry="provider"
                                    data-editor-media-action="retry-upload"
                                    data-editor-action-detail="video-provider-retry"
                                    data-upload-token="{{ $uploadToken }}"
                                    :disabled="$actionsLocked"
                                    variant="ghost"
                                    size="sm">{{ __('Try again') }}</flux:button>
                            @endif
                        @endif
                    </div>
                </article>
            @endforeach
        </section>
    @endif

    @if ($compositionMode === App\Services\Courses\CourseVersionComposition::Mixed)
        <flux:callout variant="danger" :heading="__('ui.mixed_composition_title')" :text="__('ui.mixed_composition_recovery')" />
    @endif

    @if ($preservedRecords !== [])
        <section class="detail-card space-y-3" @if($compositionMode === 'mixed') aria-describedby="mixed-module-composition-conflict" @endif>
            <div>
                <h2 class="detail-card-title">{{ __('Reusable modules') }}</h2>
                <p id="mixed-module-composition-conflict" class="mt-1 text-sm text-[#707a80]">{{ $compositionMode === 'mixed' ? __('Both direct lessons and reusable modules are preserved until an explicit non-destructive recovery.') : __('These reusable modules remain separate immutable content.') }}</p>
            </div>
            @foreach ($preservedRecords as $preserved)
                <div class="flex flex-col gap-3 rounded-xl border border-[#dde3e7] p-3 sm:flex-row sm:items-center" data-editor-record data-record-type="module" data-record-key="{{ $preserved['key'] }}">
                    <div class="min-w-0 flex-1">
                        <p class="font-bold">{{ $preserved['title'] }}</p>
                        <p class="mt-1 text-xs text-[#707a80]">{{ __('Preserved reusable module') }}</p>
                    </div>
                    @if ($isCompany && ($capabilities['manageStructure'] ?? false) && $compositionMode !== 'mixed')
                        <flux:button wire:click="confirmReusableModuleDestruction({{ $preserved['id'] }})" data-editor-structure-action="remove" data-editor-action-detail="remove-reusable-module" variant="ghost" size="sm">{{ __('Remove module') }}</flux:button>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    @if ($isCourse && ($capabilities['manageStructure'] ?? false))
        <section class="detail-card min-w-0 space-y-4" aria-labelledby="editor-module-picker-heading" x-data="{ pickerSelection: {{ Js::from($selectedModuleId) }} }">
            <div>
                <h2 id="editor-module-picker-heading" class="detail-card-title">{{ $isCompany ? __('Reusable module composition') : __('Add an existing shared module') }}</h2>
                <p class="mt-1 text-sm text-[#707a80]">
                    {{ $isCompany ? __('Choose eligible company or shared modules without mixing them with direct lessons.') : __('Search published shared modules or create a new one for this course draft.') }}
                </p>
            </div>
            @if ($context === 'shared-course' && $records === [])
                <div class="rounded-[18px] border border-dashed border-[#cfd8dd] p-5 text-center">
                    <p class="font-bold">{{ __('No modules selected') }}</p>
                    <p class="mt-1 text-sm text-[#707a80]">{{ __('Create a new shared module or add a published one to start building this course.') }}</p>
                </div>
            @endif
            <div class="flex min-w-0 flex-col gap-2 sm:flex-row">
                <flux:input wire:model="moduleSearch" wire:keydown.enter="searchAvailableModules" class="min-w-0 flex-1" :label="$isCompany ? __('Search eligible modules') : __('Search shared modules')" :placeholder="__('Search by title or code')" />
                <flux:button wire:click="searchAvailableModules" data-editor-action-detail="search-retry" class="self-end" variant="ghost">{{ __('Search') }}</flux:button>
            </div>
            <div class="grid min-w-0 gap-4 md:grid-cols-2">
                @forelse ($availableModuleGroups as $owner => $items)
                    <fieldset class="min-w-0 space-y-2 rounded-[16px] border border-[#e4e9ec] p-3">
                        <legend class="px-1 text-sm font-bold">{{ $owner === 'company' ? __('Company modules') : __('Shared modules') }}</legend>
                        @forelse ($items as $item)
                            <label class="flex min-w-0 items-start gap-3 rounded-xl p-2 hover:bg-[#f8fafb]">
                                <input type="radio" wire:model="selectedModuleId" x-on:change="pickerSelection = $el.value" value="{{ $item['id'] }}" class="mt-1 size-4 shrink-0" name="editor-module-picker">
                                <span class="min-w-0"><strong class="block break-words text-sm">{{ $item['title'] }}</strong><span class="text-xs text-[#707a80]">{{ $item['code'] }} · {{ $owner === 'company' ? __('Owned by this company') : __('Managed by platform') }}</span></span>
                            </label>
                        @empty
                            <p class="text-sm text-[#707a80]">{{ __('No eligible modules found.') }}</p>
                        @endforelse
                    </fieldset>
                @empty
                    <p class="text-sm text-[#707a80]">{{ __('No eligible modules found.') }}</p>
                @endforelse
            </div>
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                @if ($context === 'shared-course')
                    <flux:button wire:click="openNewModuleModal" data-editor-structure-action="add" data-editor-action-detail="composition-create" variant="ghost" icon="plus">{{ __('Create new shared module') }}</flux:button>
                @endif
                <flux:button
                    wire:click="addSelectedModule"
                    data-editor-structure-action="add"
                    data-editor-action-detail="composition-attach"
                    aria-describedby="editor-add-module-guidance"
                    variant="primary"
                    :disabled="$actionsLocked || $selectedModuleId === null || ($isCompany && in_array($compositionMode, ['direct_lessons', 'mixed'], true))"
                    data-editor-base-disabled="{{ ($actionsLocked || $selectedModuleId === null || ($isCompany && in_array($compositionMode, ['direct_lessons', 'mixed'], true))) ? 'true' : 'false' }}"
                    class="w-full whitespace-normal sm:w-auto"
                    x-bind:disabled="['saving', 'conflict', 'unknown-outcome', 'permission-lost'].includes(state) || {{ Js::from($isCompany && in_array($compositionMode, ['direct_lessons', 'mixed'], true)) }} || ! pickerSelection">
                    {{ __('Add module') }}
                </flux:button>
            </div>
            <p
                id="editor-add-module-guidance"
                class="text-sm font-semibold text-amber-800"
                role="status"
                aria-live="polite"
                x-cloak
                x-show="['saving', 'conflict', 'unknown-outcome', 'permission-lost'].includes(state) || {{ Js::from($isCompany && in_array($compositionMode, ['direct_lessons', 'mixed'], true)) }} || ! pickerSelection">
                <span x-show="state === 'permission-lost'">{{ __('Your editing permission was removed. Copy any local values you need; module actions are unavailable.') }}</span>
                <span x-show="state !== 'permission-lost' && ['saving', 'conflict', 'unknown-outcome'].includes(state)">{{ __('Recover the editor state before adding a module.') }}</span>
                <span x-show="! ['saving', 'conflict', 'unknown-outcome', 'permission-lost'].includes(state) && {{ Js::from($isCompany && in_array($compositionMode, ['direct_lessons', 'mixed'], true)) }}">{{ __('Resolve the course composition conflict before adding a reusable module.') }}</span>
                <span x-show="! ['saving', 'conflict', 'unknown-outcome', 'permission-lost'].includes(state) && {{ Js::from(! ($isCompany && in_array($compositionMode, ['direct_lessons', 'mixed'], true))) }} && ! pickerSelection">{{ __('Select an eligible module before adding it.') }}</span>
            </p>
        </section>
    @endif

    @if ($isCourse)
        <section class="detail-card min-w-0 space-y-4">
            <h2 class="detail-card-title">{{ __('Course details') }}</h2>
            <div class="grid min-w-0 gap-4 lg:grid-cols-[160px_minmax(0,1fr)]">
                <div data-editor-field data-field-name="course.code">
                    <flux:input wire:model.defer="courseForm.code" class="admin-control" :label="__('Code')" :readonly="$isCompany" aria-describedby="course-code-error" />
                    <flux:error id="course-code-error" name="courseForm.code" />
                </div>
                <div class="min-w-0" data-editor-field data-field-name="course.title">
                    <flux:input wire:model.defer="courseForm.title" class="admin-control w-full" :label="__('Title')" aria-describedby="course-title-error" />
                    <flux:error id="course-title-error" name="courseForm.title" />
                </div>
            </div>
            <div data-editor-field data-field-name="course.description">
                <flux:textarea wire:model.defer="courseForm.description" class="admin-control w-full" :label="__('Description')" rows="2" aria-describedby="course-description-error" />
                <flux:error id="course-description-error" name="courseForm.description" />
            </div>
            <div data-editor-field data-field-name="version.description">
                <flux:textarea wire:model.defer="versionForm.description" class="admin-control w-full" :label="__('Description shown to the employee')" rows="2" aria-describedby="version-description-error" />
                <flux:error id="version-description-error" name="versionForm.description" />
            </div>
        </section>
    @endif

    <section class="min-w-0 space-y-4" aria-labelledby="editor-content-heading">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="admin-kicker">{{ __('Content') }}</p>
                <h2 id="editor-content-heading" class="text-xl font-bold tracking-tight">{{ $context === 'company-course' ? __('Lessons') : __('Modules') }}</h2>
            </div>
            @if ($isCompany && ($capabilities['manageStructure'] ?? false))
                <flux:button wire:click="addRecord" wire:loading.attr="disabled" data-editor-structure-action="add" data-editor-action-detail="add-lesson" variant="primary" icon="plus">{{ __('Add lesson') }}</flux:button>
            @endif
        </div>

        @if ($records === [])
            <x-empty-state
                icon="rectangle-stack"
                :title="$isCompany ? __('No lessons yet') : __('No modules yet')"
                :description="$isCompany ? __('Add a lesson to begin authoring this draft.') : __('Add a module through the course composition controls.')" />
        @endif

        @foreach ($records as $recordIndex => $record)
            @php
                $recordExpanded = in_array($record['key'], $expanded, true);
                $recordErrorBase = 'editor-error-record-'.$record['id'];
            @endphp
            <article
                class="detail-card min-w-0 max-w-full"
                data-editor-record
                data-record-type="{{ $record['type'] }}"
                data-record-key="{{ $record['key'] }}"
                wire:key="editor-record-{{ $record['key'] }}">
                <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start">
                    <button
                        type="button"
                        wire:click="toggleRecord('{{ $record['key'] }}')"
                        data-editor-expand
                        class="flex min-w-0 flex-1 items-center justify-between gap-3 rounded-xl text-left"
                        aria-expanded="{{ $recordExpanded ? 'true' : 'false' }}"
                        aria-controls="editor-record-panel-{{ $record['id'] }}">
                        <span class="min-w-0">
                            <span class="block break-words font-bold text-[#262d33]">{{ $record['title'] }}</span>
                            <span class="mt-1 block text-xs text-[#8a9298]">{{ trans_choice('ui.questions_count', count($record['questions']), ['count' => count($record['questions'])]) }}</span>
                        </span>
                        <flux:icon.chevron-down class="size-5 shrink-0" />
                    </button>
                    @if (($capabilities['manageStructure'] ?? false) && count($records) > 1)
                        <div class="flex flex-wrap gap-2" aria-label="{{ __('Reorder :title', ['title' => $record['title']]) }}">
                            <flux:button wire:click="moveRecord({{ $record['id'] }}, -1)" data-editor-structure-action="move-up" data-editor-base-disabled="{{ $loop->first ? 'true' : 'false' }}" variant="ghost" size="sm" icon="arrow-up" :disabled="$loop->first" :aria-label="__('Move :title up', ['title' => $record['title']])" />
                            <flux:button wire:click="moveRecord({{ $record['id'] }}, 1)" data-editor-structure-action="move-down" data-editor-base-disabled="{{ $loop->last ? 'true' : 'false' }}" variant="ghost" size="sm" icon="arrow-down" :disabled="$loop->last" :aria-label="__('Move :title down', ['title' => $record['title']])" />
                        </div>
                    @endif
                    @if (($capabilities['manageStructure'] ?? false) && $isCompany)
                        <flux:button
                            wire:click="confirmLessonRemoval({{ $record['id'] }})"
                            data-editor-structure-action="remove"
                            data-editor-action-detail="remove-lesson"
                            variant="ghost"
                            size="sm"
                            icon="trash"
                            :aria-label="__('Remove :title', ['title' => $record['title']])" />
                    @elseif (($capabilities['manageStructure'] ?? false) && $context === 'shared-course')
                        <flux:button
                            wire:click="confirmModuleRemoval({{ $record['id'] }}, {{ $record['composition_id'] }})"
                            data-editor-structure-action="remove"
                            data-editor-action-detail="remove-reusable-module"
                            variant="ghost"
                            size="sm"
                            icon="trash"
                            :aria-label="__('Remove :title', ['title' => $record['title']])" />
                    @endif
                </div>

                @if ($recordExpanded)
                    <div id="editor-record-panel-{{ $record['id'] }}" class="mt-5 min-w-0 space-y-5 border-t border-[#e5eaed] pt-5">
                        <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                            <div class="min-w-0" data-editor-field data-editor-primary-field data-field-name="module.title">
                                <flux:input wire:model.defer="records.{{ $recordIndex }}.title" class="admin-control w-full" :label="$record['type'] === 'lesson' ? __('Lesson title') : __('Module title')" aria-describedby="{{ $recordErrorBase }}-title" />
                                <flux:error id="{{ $recordErrorBase }}-title" name="records.{{ $recordIndex }}.title" />
                            </div>
                            <div data-editor-field data-field-name="module.description">
                                <flux:textarea wire:model.defer="records.{{ $recordIndex }}.description" class="admin-control w-full" :label="__('Description')" rows="2" aria-describedby="{{ $recordErrorBase }}-description" />
                                <flux:error id="{{ $recordErrorBase }}-description" name="records.{{ $recordIndex }}.description" />
                            </div>
                        </div>

                        <div class="min-w-0" data-editor-field data-field-name="module.content">
                            <flux:editor
                                wire:model.defer="records.{{ $recordIndex }}.content_markdown"
                                data-oceanix-editor-model="records.{{ $recordIndex }}.content_markdown"
                                data-oceanix-record-key="{{ $record['key'] }}"
                                data-oceanix-video-preview-url="{{ data_get($record, 'video.preview.preview_url') }}"
                                data-oceanix-video-poster-url="{{ data_get($record, 'video.preview.poster_url') }}"
                                data-oceanix-video-title="{{ $record['title'] }}"
                                data-oceanix-video-aspect-ratio="{{ data_get($record, 'video.preview.aspect_ratio', '16/9') }}"
                                class="oceanix-content-editor min-w-0 max-w-full"
                                aria-describedby="{{ $recordErrorBase }}-content"
                                :label="__('Content')"
                                toolbar="heading | bold italic underline strike | bullet ordered blockquote link pdf | align | image image-left image-center image-right image-size video ~ fullscreen undo redo" />
                            <flux:error id="{{ $recordErrorBase }}-content" name="records.{{ $recordIndex }}.content_markdown" />
                        </div>

                        @if (($capabilities['manageMedia'] ?? false) && $record['video'] !== null)
                            <div
                                class="flex min-w-0 flex-col gap-3 rounded-[16px] border border-[#dde3e7] bg-[#f8fafb] p-3 sm:flex-row sm:items-center sm:justify-between"
                                data-editor-current-video
                                data-record-key="{{ $record['key'] }}">
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-[#262d33]">{{ __('Attached video') }}</p>
                                    <p class="mt-1 text-xs text-[#707a80]">{{ $record['video']['status_label'] }} · {{ $record['video']['duration'] }}</p>
                                </div>
                                <flux:button
                                    wire:click="confirmVideoDestruction({{ $record['id'] }}, {{ $record['video']['id'] }})"
                                    data-editor-media-action="remove"
                                    data-editor-action-detail="confirm-video-removal"
                                    data-editor-target-key="{{ $record['key'] }}"
                                    data-editor-target-label="{{ $record['title'] }}"
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    class="w-full whitespace-normal sm:w-auto">
                                    {{ __('Remove video') }}
                                </flux:button>
                            </div>
                        @endif

                        <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                            <flux:field data-editor-field data-field-name="module.watch-threshold">
                                <x-field-label for="{{ $recordErrorBase }}-watch-input">
                                    {{ __('Watch threshold (%)') }}
                                    <x-field-hint id="{{ $recordErrorBase }}-watch-hint" :text="__('Percentage tracked for reporting; assessment access remains independent of watch progress.')" />
                                </x-field-label>
                                <flux:input id="{{ $recordErrorBase }}-watch-input" type="number" min="1" max="100" wire:model.defer="records.{{ $recordIndex }}.minimum_watch_percentage" aria-describedby="{{ $recordErrorBase }}-watch-hint {{ $recordErrorBase }}-watch" :aria-invalid="$errors->has('records.'.$recordIndex.'.minimum_watch_percentage') ? 'true' : null" />
                                <flux:error id="{{ $recordErrorBase }}-watch" name="records.{{ $recordIndex }}.minimum_watch_percentage" />
                            </flux:field>
                            <flux:field data-editor-field data-field-name="module.passing-score">
                                <x-field-label for="{{ $recordErrorBase }}-passing-input">
                                    {{ __('Passing score (%)') }}
                                    <x-field-hint id="{{ $recordErrorBase }}-passing-hint" :text="__('Minimum correct-answer percentage required to pass the assessment.')" />
                                </x-field-label>
                                <flux:input id="{{ $recordErrorBase }}-passing-input" type="number" min="1" max="100" wire:model.defer="records.{{ $recordIndex }}.passing_score" aria-describedby="{{ $recordErrorBase }}-passing-hint {{ $recordErrorBase }}-passing" :aria-invalid="$errors->has('records.'.$recordIndex.'.passing_score') ? 'true' : null" />
                                <flux:error id="{{ $recordErrorBase }}-passing" name="records.{{ $recordIndex }}.passing_score" />
                            </flux:field>
                        </div>

                        <div class="min-w-0 space-y-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 class="font-bold">{{ __('Assessment') }}</h3>
                                    <p class="mt-1 text-xs text-[#707a80]">{{ __('Assessment values are committed only by Save changes.') }}</p>
                                </div>
                                @if ($capabilities['manageStructure'] ?? false)
                                    <flux:button wire:click="addQuestion({{ $record['id'] }})" data-editor-structure-action="add" data-editor-action-detail="add-question" variant="ghost" size="sm" icon="plus">{{ __('Add question') }}</flux:button>
                                @endif
                            </div>

                            @if ($record['questions'] === [])
                                <x-empty-state icon="question-mark-circle" :title="__('No assessment questions yet')" :description="__('Add a question to build this assessment.')" />
                            @endif

                            @foreach ($record['questions'] as $questionIndex => $question)
                                @php
                                    $questionErrorBase = 'editor-error-question-'.$question['id'];
                                    $questionPath = 'records.'.$recordIndex.'.questions.'.$questionIndex;
                                @endphp
                                <div
                                    class="min-w-0 max-w-full space-y-3 rounded-[18px] border border-[#e4e9ec] py-4 {{ $isCompany ? 'px-[14px] sm:px-4' : 'px-2 sm:px-4' }}"
                                    data-editor-record
                                    data-record-type="question"
                                    data-record-key="{{ $question['key'] }}"
                                    tabindex="-1"
                                    wire:key="editor-question-{{ $question['key'] }}">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="text-sm font-bold text-[#4f5960]">{{ __('Question :number', ['number' => $questionIndex + 1]) }}</p>
                                        <div class="flex flex-wrap gap-2">
                                            <flux:button wire:click="moveQuestion({{ $record['id'] }}, {{ $question['id'] }}, -1)" data-editor-structure-action="move-up" data-editor-base-disabled="{{ $loop->first ? 'true' : 'false' }}" variant="ghost" size="sm" icon="arrow-up" :disabled="$loop->first" :aria-label="__('Move question :number up', ['number' => $questionIndex + 1])" />
                                            <flux:button wire:click="moveQuestion({{ $record['id'] }}, {{ $question['id'] }}, 1)" data-editor-structure-action="move-down" data-editor-base-disabled="{{ $loop->last ? 'true' : 'false' }}" variant="ghost" size="sm" icon="arrow-down" :disabled="$loop->last" :aria-label="__('Move question :number down', ['number' => $questionIndex + 1])" />
                                            <flux:button wire:click="confirmQuestionDestruction({{ $record['id'] }}, {{ $question['id'] }})" data-editor-structure-action="remove" data-editor-action-detail="remove-question" variant="ghost" size="sm" icon="trash" :aria-label="__('Remove question :number', ['number' => $questionIndex + 1])" />
                                        </div>
                                    </div>

                                    <div class="min-w-0 space-y-3">
                                        <div class="min-w-0 w-full" data-editor-field data-editor-primary-field data-field-name="question.prompt">
                                            <flux:input wire:model.defer="{{ $questionPath }}.prompt" class="w-full" field:class="min-w-0 w-full" :label="__('Question :number', ['number' => $questionIndex + 1])" aria-describedby="{{ $questionErrorBase }}-prompt" />
                                            <flux:error id="{{ $questionErrorBase }}-prompt" name="{{ $questionPath }}.prompt" />
                                        </div>
                                        <div class="grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1fr)_140px]">
                                            <div data-editor-field data-field-name="question.type">
                                                <flux:select wire:model.defer="{{ $questionPath }}.type" :label="__('Question type')" aria-describedby="{{ $questionErrorBase }}-type">
                                                    <flux:select.option value="single_choice">{{ __('Single choice') }}</flux:select.option>
                                                    <flux:select.option value="multiple_choice">{{ __('Multiple choice') }}</flux:select.option>
                                                </flux:select>
                                                <flux:error id="{{ $questionErrorBase }}-type" name="{{ $questionPath }}.type" />
                                            </div>
                                            <flux:field data-editor-field data-field-name="question.attempts">
                                                <x-field-label for="{{ $questionErrorBase }}-attempts-input">
                                                    {{ __('Attempts') }}
                                                    <x-field-hint id="{{ $questionErrorBase }}-attempts-hint" :text="__('Maximum assessment submissions allowed for this question.')" />
                                                </x-field-label>
                                                <flux:input id="{{ $questionErrorBase }}-attempts-input" type="number" min="1" max="10" wire:model.defer="{{ $questionPath }}.max_attempts" aria-describedby="{{ $questionErrorBase }}-attempts-hint {{ $questionErrorBase }}-attempts" :aria-invalid="$errors->has($questionPath.'.max_attempts') ? 'true' : null" />
                                                <flux:error id="{{ $questionErrorBase }}-attempts" name="{{ $questionPath }}.max_attempts" />
                                            </flux:field>
                                        </div>
                                    </div>

                                    <fieldset class="min-w-0 space-y-2">
                                        <legend class="mb-2 text-sm font-semibold">{{ __('Correct answer') }}</legend>
                                        @foreach ($question['options'] as $optionIndex => $option)
                                            @php
                                                $optionErrorBase = 'editor-error-option-'.$option['id'];
                                                $optionPath = $questionPath.'.options.'.$optionIndex;
                                            @endphp
                                            <div
                                                class="flex min-w-0 flex-col gap-2 rounded-xl bg-[#f8fafb] py-2 {{ $isCompany ? 'px-2' : 'px-1 sm:px-2' }} lg:flex-row lg:items-end"
                                                data-editor-record
                                                data-record-type="option"
                                                data-record-key="{{ $option['key'] }}"
                                                tabindex="-1"
                                                wire:key="editor-option-{{ $option['key'] }}">
                                                <div class="flex shrink-0 items-center gap-2 pb-2 lg:pb-3" data-editor-field data-field-name="option.correctness">
                                                    @if ($question['type'] === 'single_choice')
                                                        <input
                                                            type="radio"
                                                            data-editor-model="{{ $optionPath }}.is_correct"
                                                            @checked($option['is_correct'])
                                                            name="correct-{{ $question['key'] }}"
                                                            data-validation-for="{{ $optionPath }}.is_correct"
                                                            aria-describedby="{{ $optionErrorBase }}-correctness"
                                                            @error($optionPath.'.is_correct') aria-invalid="true" @enderror
                                                            aria-label="{{ __('Mark answer :number as correct', ['number' => $optionIndex + 1]) }}">
                                                    @else
                                                        <input
                                                            type="checkbox"
                                                            wire:model.defer="{{ $optionPath }}.is_correct"
                                                            data-validation-for="{{ $optionPath }}.is_correct"
                                                            aria-describedby="{{ $optionErrorBase }}-correctness"
                                                            @error($optionPath.'.is_correct') aria-invalid="true" @enderror
                                                            aria-label="{{ __('Mark answer :number as correct', ['number' => $optionIndex + 1]) }}">
                                                    @endif
                                                    <flux:error id="{{ $optionErrorBase }}-correctness" name="{{ $optionPath }}.is_correct" />
                                                </div>
                                                <div class="min-w-0 w-full flex-1" data-editor-field data-editor-primary-field data-field-name="option.text">
                                                    <flux:input wire:model.defer="{{ $optionPath }}.text" class="w-full" field:class="min-w-0 w-full flex-1" :label="__('Answer :number', ['number' => $optionIndex + 1])" aria-describedby="{{ $optionErrorBase }}-text" />
                                                    <flux:error id="{{ $optionErrorBase }}-text" name="{{ $optionPath }}.text" />
                                                </div>
                                                <div class="flex flex-wrap gap-1 lg:pb-1">
                                                    <flux:button wire:click="moveOption({{ $record['id'] }}, {{ $question['id'] }}, {{ $option['id'] }}, -1)" data-editor-structure-action="move-up" data-editor-base-disabled="{{ $loop->first ? 'true' : 'false' }}" variant="ghost" size="sm" icon="arrow-up" :disabled="$loop->first" :aria-label="__('Move answer :number up', ['number' => $optionIndex + 1])" />
                                                    <flux:button wire:click="moveOption({{ $record['id'] }}, {{ $question['id'] }}, {{ $option['id'] }}, 1)" data-editor-structure-action="move-down" data-editor-base-disabled="{{ $loop->last ? 'true' : 'false' }}" variant="ghost" size="sm" icon="arrow-down" :disabled="$loop->last" :aria-label="__('Move answer :number down', ['number' => $optionIndex + 1])" />
                                                    <flux:button wire:click="confirmAnswerDestruction({{ $record['id'] }}, {{ $question['id'] }}, {{ $option['id'] }})" data-editor-structure-action="remove" data-editor-action-detail="remove-option" variant="ghost" size="sm" icon="x-mark" :aria-label="__('Remove answer :number', ['number' => $optionIndex + 1])" />
                                                </div>
                                            </div>
                                        @endforeach
                                    </fieldset>

                                    <flux:button wire:click="addOption({{ $record['id'] }}, {{ $question['id'] }})" data-editor-structure-action="add" data-editor-action-detail="add-option" variant="ghost" size="sm" icon="plus">{{ __('Add option') }}</flux:button>
                                </div>
                            @endforeach
                        </div>

                    </div>
                @endif
            </article>
        @endforeach
    </section>

    <flux:modal wire:model.self="videoLibraryOpen" class="max-w-4xl">
        <div class="min-w-0 space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Video library') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Upload a video or select a ready private video for this draft record.') }}</flux:text>
            </div>

            @if ($videoLibraryRecordId !== null)
                <div
                    class="min-w-0 rounded-[18px] border border-dashed border-[#cfd8dd] bg-[#f7f9fa] p-4"
                    wire:key="editor-video-upload-{{ $videoLibraryRecordId }}"
                    data-editor-upload-picker
                    data-record-key="{{ $videoLibraryRecordKey }}"
                    data-upload-state="{{ $videoLibraryUpload['state'] ?? 'idle' }}"
                    x-data="lessonVideoUpload({{ $videoLibraryRecordId }}, {{ Js::from([
                        'fileTooLarge' => __('This video is larger than 200 MB. Select a smaller file.'),
                        'reselectFile' => __('Choose the video file again to retry this upload.'),
                        'restartFailed' => __('The video upload could not be restarted. Try again.'),
                    ]) }}, {{ Js::from($videoLibraryRecordKey) }})"
                    x-bind:data-upload-state="uploading ? 'uploading' : {{ Js::from($videoLibraryUpload['state'] ?? 'idle') }}"
                    x-on:oceanix:retry-video-upload.window="retryTransfer($event.detail)">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-bold">{{ __('Upload a new video') }}</p>
                            <p class="mt-1 text-xs text-[#707a80]">{{ __('The file uploads directly and remains bound to this record if the list is reordered.') }}</p>
                        </div>
                        <div class="shrink-0">
                            <input type="file" accept="video/*" class="hidden" x-ref="file" x-on:change="start($event)">
                            <flux:button data-editor-media-action="upload" data-editor-action-detail="video-upload" variant="primary" icon="arrow-up-tray" x-on:click="$refs.file.click()" ::disabled="uploading">
                                <span x-show="! uploading">{{ __('Choose video') }}</span>
                                <span x-show="uploading" x-text="`${progress}%`"></span>
                            </flux:button>
                        </div>
                    </div>
                    <p class="mt-3 text-sm font-medium text-red-600" x-show="error" x-text="error" role="alert" aria-live="assertive"></p>
                </div>
            @endif

            <div class="flex min-w-0 flex-col gap-2 sm:flex-row">
                <flux:input wire:model="videoLibrarySearch" wire:keydown.enter="searchVideoLibrary" class="min-w-0 flex-1" :label="__('Search videos')" :placeholder="__('Search by video name')" />
                <flux:button wire:click="searchVideoLibrary" data-editor-action-detail="search-retry" class="self-end" variant="ghost">{{ __('Search') }}</flux:button>
            </div>

            @if ($videoLibraryError)
                <div role="alert" aria-live="assertive">
                    <flux:callout variant="danger" :heading="$videoLibraryError">
                        <flux:button wire:click="searchVideoLibrary" data-editor-action-detail="search-retry" class="mt-3" variant="ghost" size="sm">{{ __('Try again') }}</flux:button>
                    </flux:callout>
                </div>
            @elseif ($videoLibraryItems === [])
                <x-empty-state icon="film" :title="__('No videos found')" :description="__('Upload a video and it will appear here while processing.')" />
            @else
                <div class="grid max-h-[55vh] min-w-0 gap-3 overflow-y-auto sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($videoLibraryItems as $item)
                        <article class="min-w-0 overflow-hidden rounded-2xl border border-[#dde3e7] bg-white" wire:key="editor-video-{{ $item['asset_id'] }}">
                            <div class="aspect-video bg-[#e8eef1]">
                                @if ($item['thumbnail_url'])
                                    <img src="{{ $item['thumbnail_url'] }}" alt="" class="size-full object-cover">
                                @else
                                    <span class="grid size-full place-items-center"><flux:icon.film class="size-8 text-[#8a9298]" /></span>
                                @endif
                            </div>
                            <div class="space-y-2 p-3">
                                <p class="truncate text-sm font-bold">{{ $item['title'] }}</p>
                                <p class="text-xs text-[#7d878e]">{{ $item['duration'] }} · {{ $item['status_label'] }}</p>
                                <flux:button
                                    wire:click="selectLibraryVideo('{{ $item['asset_id'] }}')"
                                    data-editor-media-action="attach"
                                    data-editor-action-detail="video-attach"
                                    data-editor-target-key="{{ $videoLibraryRecordKey }}"
                                    variant="primary"
                                    size="sm"
                                    class="w-full"
                                    data-editor-base-disabled="{{ $item['status'] !== App\Enums\VideoStatus::Ready->value ? 'true' : 'false' }}"
                                    :disabled="$item['status'] !== App\Enums\VideoStatus::Ready->value">
                                    {{ __('Use video') }}
                                </flux:button>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </flux:modal>

    <flux:modal wire:model.self="pdfModalOpen" class="w-full max-w-3xl" x-on:close="window.oceanixCancelPdf()" x-on:cancel="window.oceanixCancelPdf()">
        <div class="min-w-0 space-y-5" x-data="{ restorePdfFocus(id) { this.$nextTick(() => { const row = id && document.querySelector('[data-pdf-row=\u0022' + id + '\u0022]'); (row?.querySelector('[data-pdf-archive]') || document.getElementById('pdf-library-search') || document.getElementById('pdf-link-text'))?.focus(); }); } }" x-on:oceanix:pdf-archive-cancelled.window="restorePdfFocus($event.detail.id)" x-on:oceanix:pdf-archived.window="restorePdfFocus($event.detail.id)">
        <flux:heading size="lg">{{ __('Insert PDF') }}</flux:heading>
        <flux:text>{{ $isCompany ? __('Upload or reuse PDFs owned by this company.') : __('Upload or reuse PDFs owned by the platform.') }}</flux:text>
        <flux:input id="pdf-link-text" wire:model="pdfLinkText" :label="__('Link text')" maxlength="100000" autofocus />
        <flux:text>{{ __('Leave blank to use the filename.') }}</flux:text>
        <form wire:submit="uploadPdf" class="space-y-5" x-data="{ uploading: false }" x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-cancel="uploading = false">
            <label class="block text-sm font-medium">{{ __('PDF file') }}
                <input wire:model="pdfUpload" type="file" accept="application/pdf,.pdf" x-on:change="if ($wire.pdfLinkText === '') $wire.pdfLinkText = $event.target.files[0]?.name || ''" aria-describedby="editor-pdf-help editor-pdf-error" @error('pdfUpload') aria-invalid="true" @enderror class="mt-2 block w-full min-w-0 rounded-xl border border-[#cfd8dd] bg-white p-3 text-sm focus-ring">
            </label>
            <p id="editor-pdf-help" class="text-sm text-[#707a80]">{{ __('PDF only, up to 10 MB. Opens in a new tab for people with access to this training.') }}</p>
            <div id="editor-pdf-error" role="alert">@error('pdfUpload')<p class="text-sm text-red-600">{{ $message }}</p>@enderror</div>
            <p x-show="uploading" role="status" class="text-sm">{{ __('Uploading PDF…') }}</p>
            <div class="flex flex-wrap justify-end gap-3">
                <flux:button type="submit" variant="primary" x-bind:disabled="uploading" wire:loading.attr="disabled" wire:target="pdfUpload,uploadPdf" data-editor-media-action="upload" data-editor-media-family="pdf" data-editor-action-detail="pdf-upload" data-editor-target-key="{{ $pdfRecordKey }}">{{ __('Upload and insert link') }}</flux:button>
            </div>
        </form>
        <section aria-label="{{ __('PDF library') }}" class="space-y-4 border-t border-[var(--ds-border-default)] pt-5">
            <flux:heading>{{ __('PDF library') }}</flux:heading>
            <div x-cloak x-show="pdfTransportFailure && pdfTransportFailure.method !== 'archivePdf'" role="alert" class="rounded-xl border border-[var(--ds-status-negative)] p-3">
                <p>{{ __('The PDF request was interrupted. Results may be out of date. Your text and selection are preserved.') }}</p>
                <flux:button type="button" x-on:click="retryPdfRequest()" class="mt-2">{{ __('Try again') }}</flux:button>
            </div>
            @if($pdfLibraryError)
                <p role="alert">{{ $pdfLibraryError }}</p>
                <flux:button type="button" wire:click="loadPdfLibrary" wire:loading.attr="disabled">{{ __('Try again') }}</flux:button>
            @else
                <form wire:submit="searchPdfs" class="flex flex-wrap items-end gap-2">
                    <div class="min-w-0 flex-1"><flux:input id="pdf-library-search" wire:model="pdfSearch" :label="__('Search filenames')" maxlength="240" /></div>
                    <flux:button type="submit" wire:loading.attr="disabled">{{ __('Search') }}</flux:button>
                    @if($pdfSearch !== '')<flux:button type="button" wire:click="clearPdfSearch" wire:loading.attr="disabled">{{ __('Clear search') }}</flux:button>@endif
                </form>
                <p wire:loading wire:target="loadPdfLibrary,searchPdfs,clearPdfSearch" role="status">{{ __('Loading PDFs…') }}</p>
                <p wire:loading wire:target="reusePdf" role="status">{{ __('Inserting PDF link…') }}</p>
                @error('pdfLibrary')<p role="alert" class="text-[var(--ds-status-negative)]">{{ $message }}</p>@enderror
                <ul class="space-y-3" wire:loading.attr="aria-busy" wire:target="loadPdfLibrary,searchPdfs,clearPdfSearch">
                    @forelse($pdfLibrary['items'] ?? [] as $pdf)
                        <li wire:key="library-pdf-{{ $pdf['id'] }}" data-pdf-row="{{ $pdf['id'] }}" class="rounded-xl border border-[var(--ds-border-default)] p-3">
                            <p class="min-w-0 font-medium [overflow-wrap:anywhere]">{{ $pdf['name'] }}</p>
                            <p class="mt-1 text-sm text-[var(--ds-text-secondary)]">{{ \Illuminate\Support\Number::fileSize($pdf['size_bytes']) }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <flux:button :href="$pdf['open_url']" target="_blank" rel="noopener noreferrer" :aria-label="__('Open :name (opens in a new tab)', ['name' => $pdf['name']])">{{ __('Open PDF') }}</flux:button>
                                @if($pdf['can_reuse'])<flux:button type="button" variant="primary" class="admin-primary-action" wire:click="reusePdf('{{ $pdf['id'] }}')" wire:loading.attr="disabled" :aria-label="__('Reuse :name', ['name' => $pdf['name']])" data-editor-media-action="select" data-editor-media-family="pdf" data-editor-action-detail="pdf-reuse" data-editor-target-key="{{ $pdfRecordKey }}">{{ __('Reuse') }}</flux:button>@endif
                                @if($pdf['can_archive'])<flux:button type="button" variant="ghost" style="color: var(--ds-status-negative) !important" data-pdf-archive wire:click="requestArchivePdf('{{ $pdf['id'] }}')" wire:loading.attr="disabled" :aria-label="__('Archive :name', ['name' => $pdf['name']])">{{ __('Archive') }}</flux:button>@endif
                            </div>
                        </li>
                    @empty
                        <li><x-empty-state icon="document" :title="$pdfSearch === '' ? __('No PDFs in this library yet') : __('No PDFs match your search')" :description="$pdfSearch === '' ? __('Upload a PDF to make it available here.') : __('Try another filename or clear the search.')" /></li>
                    @endforelse
                </ul>
                @if(($pdfLibrary['last_page'] ?? 1) > 1)
                    <nav aria-label="{{ __('PDF library pages') }}" class="flex flex-wrap items-center justify-between gap-2">
                        <flux:button type="button" wire:click="loadPdfLibrary({{ max(1, $pdfLibrary['current_page'] - 1) }})" :disabled="$pdfLibrary['current_page'] === 1" wire:loading.attr="disabled">{{ __('Previous') }}</flux:button>
                        <span aria-current="page">{{ __('Page :page of :pages', ['page' => $pdfLibrary['current_page'], 'pages' => $pdfLibrary['last_page']]) }}</span>
                        <flux:button type="button" wire:click="loadPdfLibrary({{ $pdfLibrary['current_page'] + 1 }})" :disabled="$pdfLibrary['current_page'] === $pdfLibrary['last_page']" wire:loading.attr="disabled">{{ __('Next') }}</flux:button>
                    </nav>
                @endif
            @endif
            <p role="status" aria-live="polite">{{ $pdfLibraryNotice }}</p>
        </section>
        <div class="flex justify-end"><flux:button type="button" x-on:click="window.oceanixCancelPdf(); $wire.pdfModalOpen = false">{{ __('Cancel') }}</flux:button></div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="pdfArchiveModalOpen" class="w-full max-w-lg" :dismissible="false" :closable="false" :escapable="false" x-on:keydown.escape.stop.prevent="if (!$el.querySelector('[data-pdf-confirm]')?.disabled) $wire.cancelArchivePdf()">
        @if($pdfArchiveConfirmation)
            <div class="space-y-5" x-data="{ focusCancel() { setTimeout(() => this.$refs.cancel?.focus(), 100) } }" x-init="focusCancel()" x-on:oceanix:pdf-archive-failed.window="focusCancel()">
                <flux:heading size="lg" class="[overflow-wrap:anywhere]">{{ __('Archive ‘:name’?', ['name' => $pdfArchiveConfirmation['name']]) }}</flux:heading>
                <flux:text>{{ __('This PDF will no longer appear in the library or be available for new reuse. Existing lesson links will continue to work.') }}</flux:text>
                @error('pdfArchive')<p role="alert">{{ $message }}</p>@enderror
                <p wire:loading wire:target="archivePdf" role="status" aria-live="polite">{{ __('Archiving PDF…') }}</p>
                <p x-cloak x-show="pdfTransportFailure?.method === 'archivePdf'" role="alert">{{ __('The archive response was interrupted. Try Archive PDF again to confirm the result. Existing links still work.') }}</p>
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button type="button" x-ref="cancel" wire:click="cancelArchivePdf" wire:loading.attr="disabled" wire:target="archivePdf">{{ __('Cancel') }}</flux:button>
                    <flux:button type="button" variant="danger" style="background: var(--ds-status-negative) !important; border-color: var(--ds-status-negative) !important; color: white !important" data-pdf-confirm wire:click="archivePdf" wire:loading.attr="disabled" wire:target="archivePdf">{{ __('Archive PDF') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal wire:model.self="imageLibraryOpen" class="max-w-4xl">
        <div class="min-w-0 space-y-6">
            <div><flux:heading size="lg">{{ __('Image library') }}</flux:heading><flux:text class="mt-2">{{ $isCompany ? __('Upload an image or reuse one owned by this company.') : __('Upload an image or reuse one from the shared gallery.') }}</flux:text></div>
            <form wire:submit="uploadContentImage" class="min-w-0 rounded-[18px] border border-dashed border-[#cfd8dd] bg-[#f7f9fa] p-5">
                <label class="block text-sm font-bold">{{ __('Upload from computer') }}
                    <input wire:model="contentImageUpload" type="file" accept="image/jpeg,image/png,image/webp,image/gif" aria-describedby="editor-content-image-help @error('contentImageUpload') editor-content-image-error @enderror" @error('contentImageUpload') aria-invalid="true" @enderror class="mt-3 block w-full min-w-0 rounded-xl border border-[#cfd8dd] bg-white p-3 text-sm">
                </label>
                <p id="editor-content-image-help" class="mt-2 text-xs text-[#707a80]">{{ __('JPG, PNG, WebP or GIF, up to 10 MB.') }}</p>
                @error('contentImageUpload')<p id="editor-content-image-error" class="mt-2 text-sm font-medium text-red-600" role="alert" aria-live="assertive">{{ $message }}</p>@enderror
                <flux:button type="submit" data-editor-media-action="upload" data-editor-media-family="image" data-editor-action-detail="image-upload" data-editor-target-key="{{ $imageLibraryRecordKey }}" wire:loading.attr="disabled" wire:target="contentImageUpload,uploadContentImage" variant="primary" class="mt-4">{{ __('Upload and insert image') }}</flux:button>
            </form>
            @if ($contentImages === [])
                <x-empty-state icon="photo" :title="__('No images have been uploaded yet.')" :description="__('Upload an image to make it available in this gallery.')" />
            @else
                <div class="grid max-h-[45vh] min-w-0 grid-cols-2 gap-3 overflow-y-auto sm:grid-cols-3 lg:grid-cols-4">
                    @foreach ($contentImages as $image)
                        <button type="button" wire:click="selectContentImage({{ $image['id'] }})" data-editor-media-action="attach" data-editor-media-family="image" data-editor-action-detail="image-select" data-editor-target-key="{{ $imageLibraryRecordKey }}" class="min-w-0 overflow-hidden rounded-2xl border border-[#dde3e7] bg-white text-left" wire:key="editor-content-image-{{ $image['id'] }}">
                            <img src="{{ $image['url'] }}" alt="" class="aspect-[4/3] w-full object-cover">
                            <span class="block truncate px-3 py-2 text-xs font-semibold">{{ $image['name'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </flux:modal>

    <flux:modal wire:model.self="newModuleModalOpen" class="max-w-lg">
        <form wire:submit="createNewModule" class="space-y-5">
            <div><flux:heading size="lg">{{ __('Create new shared module') }}</flux:heading><flux:text class="mt-2">{{ __('The new draft module will be attached to this course immediately.') }}</flux:text></div>
            @if ($newModuleError)<flux:callout variant="danger" :heading="$newModuleError"><flux:button type="submit" data-editor-structure-action="add" data-editor-action-detail="composition-create" class="mt-3" variant="ghost" size="sm">{{ __('Try again') }}</flux:button></flux:callout>@endif
            <flux:input wire:model="newModuleForm.code" :label="__('Code')" required />
            <flux:input wire:model="newModuleForm.title" :label="__('Module title')" required />
            <flux:textarea wire:model="newModuleForm.description" :label="__('Description')" rows="3" />
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:button type="button" x-on:click="$flux.modal.close()" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" data-editor-structure-action="add" data-editor-action-detail="composition-create" variant="primary">{{ __('Create and add module') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-course-editor.destructive-confirmation
        model="confirmingDestructive"
        :title="$destructiveTitle"
        :consequence="$destructiveConsequence"
        :submit-label="$destructiveSubmitLabel"
        submit-action="performConfirmedDestructive"
        :action-detail="$destructiveAction"
        :target-key="$destructiveTargetKey"
        :hook-type="$destructiveAction === 'remove-video' ? 'media' : 'structure'" />

    <x-course-editor.destructive-confirmation
        model="confirmingModuleRemoval"
        :title="__('Remove “:title” from this course draft?', ['title' => $moduleRemovalTitle])"
        :consequence="__('This removes only this course association. The shared module and its authored content remain available. The audit reason is required.')"
        :submit-label="__('Remove module from course')"
        submit-action="removeConfirmedModule"
        action-detail="remove-reusable-module"
        :target-key="$moduleRemovalRecordKey ?? 'editor'">
        <flux:textarea wire:model="moduleRemovalReason" :label="__('Audit reason')" rows="3" required maxlength="500" aria-describedby="module-removal-reason-error" />
        <flux:error id="module-removal-reason-error" name="moduleRemovalReason" />
    </x-course-editor.destructive-confirmation>

    @if ($capabilities['publish'] ?? false)
        <section class="detail-card space-y-4">
            <h2 class="detail-card-title">{{ __('Publish') }}</h2>
            @error('publish')<flux:callout variant="danger" :heading="$message" />@enderror
            @if ($publicationProblems !== [])
                <flux:callout variant="warning" :heading="$publicationConfirmation['problems_title']">
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                        @foreach ($publicationProblems as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                </flux:callout>
            @endif
            @if ($publicationImpact !== [])
                <div class="grid gap-3 sm:grid-cols-3" aria-label="{{ __('Publication impact') }}">
                    @foreach ($publicationImpact as $impactKey => $impactValue)
                        <div class="metric-card metric-card--slate">
                            <p class="metric-label">{{ __(str_replace('_', ' ', ucfirst($impactKey))) }}</p>
                            <p class="metric-value">{{ $impactValue }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="text-sm text-[#707a80]">{{ __('Publishing updates future training while preserving completed history. Restarting in-progress assignments does not rewrite completed evidence.') }}</p>
            @endif
            <flux:button
                wire:click="confirmPublish"
                data-editor-publish-action
                aria-describedby="editor-publish-guidance"
                wire:loading.attr="disabled"
                wire:target="confirmPublish,publish"
                :disabled="$actionsLocked || $editorDirty || $uploadInProgress || $compositionMode === 'mixed' || $publicationProblems !== []"
                x-bind:disabled="state === 'permission-lost' || dirty || state === 'saving' || {{ Js::from($uploadInProgress || $compositionMode === 'mixed' || $publicationProblems !== []) }}"
                class="w-full max-w-full !whitespace-normal sm:w-auto"
                variant="primary">{{ $publicationConfirmation['submit_label'] }}</flux:button>
            <p
                id="editor-publish-guidance"
                class="text-sm font-semibold text-amber-800"
                role="status"
                aria-live="polite"
                x-cloak
                x-show="state === 'permission-lost' || dirty || state === 'saving' || {{ Js::from($uploadInProgress || $compositionMode === 'mixed' || $publicationProblems !== []) }}">
                <span x-show="state === 'permission-lost'">{{ __('Your publishing permission was removed. Your local values remain available to copy.') }}</span>
                <span x-show="state !== 'permission-lost' && (dirty || state === 'saving')">{{ __('Save your authored changes before publishing.') }}</span>
                <span x-show="state !== 'permission-lost' && ! dirty && state !== 'saving' && {{ Js::from($uploadInProgress) }}">{{ __('Wait for active uploads to finish before publishing.') }}</span>
                <span x-show="state !== 'permission-lost' && ! dirty && state !== 'saving' && {{ Js::from(! $uploadInProgress && $compositionMode === 'mixed') }}">{{ __('Resolve the course composition conflict before publishing.') }}</span>
                <span x-show="state !== 'permission-lost' && ! dirty && state !== 'saving' && {{ Js::from(! $uploadInProgress && $compositionMode !== 'mixed' && $publicationProblems !== []) }}">{{ __('Resolve the publication readiness problems listed above before publishing.') }}</span>
            </p>
        </section>
    @endif

    <flux:modal wire:model.self="confirmingPublish" class="max-w-xl">
        <form wire:submit="publish" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $publicationConfirmation['title'] }}</flux:heading>
                <flux:text class="mt-2">{{ $publicationConfirmation['body'] }}</flux:text>
            </div>
            @error('publish')<flux:callout variant="danger" :heading="$message" />@enderror
            @if ($publicationProblems !== [])
                <flux:callout variant="danger" :heading="$publicationConfirmation['problems_title']">
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                        @foreach ($publicationProblems as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                </flux:callout>
            @endif
            @if ($publicationImpact !== [])
                <div class="grid gap-3 sm:grid-cols-3" aria-label="{{ __('Publication impact') }}">
                    @foreach ($publicationImpact as $impactKey => $impactValue)
                        <div class="metric-card metric-card--slate">
                            <p class="metric-label">{{ __(str_replace('_', ' ', ucfirst($impactKey))) }}</p>
                            <p class="metric-value">{{ $impactValue }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
            @if ($publicationConfirmation['assignment_mode'] && ($publicationImpact['open'] ?? 0) > 0)
                <fieldset class="space-y-3">
                    <legend class="text-sm font-bold">{{ trans_choice('ui.existing_assignments_title', $publicationImpact['open'], ['count' => $publicationImpact['open']]) }}</legend>
                    <label class="flex gap-3 rounded-xl border border-[#dde3e7] p-3">
                        <input type="radio" wire:model="assignmentUpdateMode" value="keep_existing" name="assignment-update-mode">
                        <span><strong class="block text-sm">{{ __('ui.keep_existing_assignments') }}</strong><span class="block text-xs text-[#707a80]">{{ __('ui.keep_existing_assignments_help') }}</span></span>
                    </label>
                    <label class="flex gap-3 rounded-xl border border-[#dde3e7] p-3">
                        <input type="radio" wire:model="assignmentUpdateMode" value="replace_open" name="assignment-update-mode">
                        <span><strong class="block text-sm">{{ __('ui.replace_open_assignments') }}</strong><span class="block text-xs text-[#707a80]">{{ __('ui.replace_open_assignments_help') }}</span></span>
                    </label>
                </fieldset>
            @endif
            @if ($publicationConfirmation['restart_in_progress'])
                <flux:checkbox wire:model="restartInProgress" :label="__('Restart in-progress assignments')" :description="$publicationConfirmation['restart_description']" />
            @endif
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:button type="button" wire:click="$set('confirmingPublish', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" data-editor-publish-action aria-describedby="editor-publish-confirm-guidance" wire:loading.attr="disabled" wire:target="publish" :disabled="$actionsLocked || $publicationProblems !== [] || $editorDirty || $uploadInProgress" x-bind:disabled="state === 'permission-lost' || dirty || state === 'saving' || {{ Js::from($uploadInProgress || $publicationProblems !== []) }}" variant="primary">
                    <span wire:loading.remove wire:target="publish">{{ $publicationConfirmation['submit_label'] }}</span>
                    <span wire:loading wire:target="publish" role="status">{{ __('ui.publishing_version') }}</span>
                </flux:button>
            </div>
            <p
                id="editor-publish-confirm-guidance"
                class="text-sm font-semibold text-amber-800"
                role="status"
                aria-live="polite"
                x-cloak
                x-show="state === 'permission-lost' || dirty || state === 'saving' || {{ Js::from($uploadInProgress || $publicationProblems !== []) }}">
                <span x-show="state === 'permission-lost'">{{ __('Your publishing permission was removed. Your local values remain available to copy.') }}</span>
                <span x-show="state !== 'permission-lost' && (dirty || state === 'saving')">{{ __('Save your authored changes before publishing.') }}</span>
                <span x-show="state !== 'permission-lost' && ! dirty && state !== 'saving' && {{ Js::from($uploadInProgress) }}">{{ __('Wait for active uploads to finish before publishing.') }}</span>
                <span x-show="state !== 'permission-lost' && ! dirty && state !== 'saving' && {{ Js::from(! $uploadInProgress && $publicationProblems !== []) }}">{{ __('Resolve the publication readiness problems listed above before publishing.') }}</span>
            </p>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="confirmingReload" class="max-w-lg">
        <div class="space-y-5" data-editor-conflict-confirmation>
            <div>
                <flux:heading size="lg">{{ __('Reload the latest saved draft?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('This will replace the unsaved values currently shown in this editor. Copy anything you need before continuing.') }}</flux:text>
            </div>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:button type="button" wire:click="$set('confirmingReload', false)" variant="ghost">{{ __('Keep my unsaved values') }}</flux:button>
                <flux:button type="button" wire:click="reloadLatestDraft" data-editor-conflict-action="confirm-reload" variant="danger">{{ __('Reload and discard local values') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <div
        @class([
            'fixed inset-x-0 bottom-0 z-40 border-t border-[#dce3e7] bg-white/95 py-4 shadow-[0_-12px_30px_rgba(31,38,43,.08)] backdrop-blur',
            'lg:left-64' => $isCompany,
        ])
        role="region"
        aria-label="{{ __('Draft save actions') }}"
        :aria-busy="state === 'saving'"
        x-init="observeSaveBar($el)">
        <div @class([
            'mx-auto flex w-full max-w-[1480px] flex-col gap-3 sm:flex-row sm:items-center sm:justify-between',
            'px-4 sm:px-7 lg:px-10' => $isCompany,
            'px-5 sm:px-8' => ! $isCompany,
        ])>
            <div class="text-sm font-semibold" aria-live="polite">
                <span x-show="! dirty" class="text-emerald-700">{{ __('All changes saved') }}</span>
                <span x-show="dirty && state !== 'saving'" class="text-amber-700">{{ __('Unsaved changes') }}</span>
                <span x-show="state === 'saving'" class="text-[#1c6b84]">{{ __('Saving changes…') }}</span>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <flux:button
                    x-on:click="submitSave(false)"
                    x-bind:disabled="! dirty || ['saving', 'conflict', 'unknown-outcome', 'permission-lost'].includes(state)"
                    wire:loading.attr="disabled"
                    wire:target="saveDraft"
                    data-editor-save
                    variant="primary"
                    class="w-full sm:w-auto">{{ __('Save changes') }}</flux:button>
                <flux:button
                    x-on:click="submitSave(true)"
                    x-bind:disabled="! ready || ['saving', 'conflict', 'unknown-outcome', 'permission-lost'].includes(state) || hasActiveUpload()"
                    wire:loading.attr="disabled"
                    wire:target="saveDraft"
                    data-editor-save-close
                    aria-describedby="editor-upload-close-guidance"
                    variant="ghost"
                    class="w-full sm:w-auto">{{ __('Save and close') }}</flux:button>
            </div>
        </div>
    </div>

</div>
