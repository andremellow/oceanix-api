<?php

use App\Livewire\CourseEditor\Contexts\CompanyCourseEditorContext;
use App\Livewire\CourseEditor\Contexts\EditorContext;
use App\Livewire\CourseEditor\EditorCoordinator;
use App\Models\Course;

new class extends EditorCoordinator
{
    public Course $course;

    public function mount(Course $course): void
    {
        $this->course = $course;
        $this->mountEditor($course->id);
    }

    protected function editorContext(): EditorContext
    {
        return app(CompanyCourseEditorContext::class);
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
