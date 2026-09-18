<?php

use App\Livewire\CourseEditor\Contexts\EditorContext;
use App\Livewire\CourseEditor\Contexts\SharedModuleEditorContext;
use App\Livewire\CourseEditor\EditorCoordinator;
use App\Models\Module;
use Livewire\Attributes\Layout;

new #[Layout('layouts::platform')] class extends EditorCoordinator
{
    public Module $module;

    public function mount(Module $module): void
    {
        $this->module = $module;
        $this->mountEditor($module->id);
    }

    protected function editorContext(): EditorContext
    {
        return app(SharedModuleEditorContext::class);
    }
};
?>

<x-course-editor.root
    :context="$editorContextName"
    :editor-root-id="$editorRootId"
    :editor-close-url="$editorCloseUrl"
    :$courseForm
    :$versionForm
    :$records
    :$revisions
    :$capabilities
    :$compositionMode
    :$expanded
    :$editorDirty
    :$saveState
    :$saveError
    :$errorKind
    :$localGeneration
    :$savedAt
    :$uploadInProgress
    :$activeUploads
    :$uploadRows
    :$operations
    :$preservedRecords
    :$videoLibraryOpen
    :$videoLibraryRecordId
    :$videoLibraryRecordKey
    :$videoLibraryItems
    :$videoLibrarySearch
    :$videoLibraryError
    :$moduleSearch
    :$selectedModuleId
    :$availableModuleGroups
    :$newModuleModalOpen
    :$newModuleForm
    :$newModuleError
    :$publicationImpact
    :$publicationProblems
    :$publicationConfirmation
    :$confirmingPublish
    :$confirmingDestructive
    :$destructiveAction
    :$destructiveTitle
    :$destructiveConsequence
    :$destructiveSubmitLabel
    :$destructiveTargetKey
    :$confirmingModuleRemoval
    :$moduleRemovalRecordKey
    :$moduleRemovalTitle
    :$moduleRemovalReason
    :$assignmentUpdateMode
    :$restartInProgress
    :$previewPanel
    :$imageLibraryOpen
    :$imageLibraryRecordKey
    :$pdfRecordKey
    :$pdfLibrary
    :$pdfLibraryError
    :$pdfLibraryNotice
    :$pdfSearch
    :$pdfArchiveConfirmation
    :$contentImages />
