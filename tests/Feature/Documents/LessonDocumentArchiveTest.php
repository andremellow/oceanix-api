<?php

use App\Actions\Documents\ArchiveLessonDocument;
use App\Actions\Documents\ReuseLessonDocument;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\Lesson;
use App\Models\LessonDocumentArchive;
use App\Models\UserTrainingAssignment;
use App\Services\Documents\LessonDocumentLibrary;
use App\Services\Documents\LessonDocumentLinks;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Storage::fake('lesson_documents'));

it('archives once retaining original actor time bytes metadata and pending explicit save', function (string $context): void {
    [$fixture, $editor] = pdfEditor($context);
    if ($fixture->user) {
        grantPermissions($fixture->user, [Permission::LessonDocumentsReuse, Permission::LessonDocumentsArchive]);
    }
    $actor = $fixture->user ?? Account::findOrFail($fixture->session['platform_account_id']);
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $document = libraryPdf(['company_id' => $lesson->company_id, 'is_shared' => $lesson->is_shared]);
    $source = Lesson::factory()->create(['company_id' => $lesson->company_id, 'is_shared' => $lesson->is_shared, 'content_markdown' => '<p><a href="/lesson-documents/'.$document->public_id.'">Original source</a></p>', 'status' => 'published']);
    $source->documents()->attach($document);
    $sourceHtml = $source->content_markdown;
    $metadata = $document->fresh()->getAttributes();
    $hash = hash('sha256', Storage::disk($document->disk)->get($document->path));
    $editor->call('openPdfModal', 'records.0.content_markdown', 'Kept', 'archive-reuse')
        ->call('reusePdf', $document->public_id)->assertDispatched('oceanix:insert-pdf');
    app(ArchiveLessonDocument::class)->handle($document->public_id, $actor);
    $archive = LessonDocumentArchive::sole();
    $first = $archive->getAttributes();
    $this->travel(1)->minutes();
    app(ArchiveLessonDocument::class)->handle($document->public_id, $actor);
    expect(LessonDocumentArchive::sole()->getAttributes())->toBe($first)
        ->and($archive->archived_by_user_id)->toBe($fixture->user?->id)
        ->and($archive->archived_by_account_id)->toBe($fixture->user ? null : $actor->id)
        ->and($document->fresh()->getAttributes())->toBe($metadata)
        ->and(hash('sha256', Storage::disk($document->disk)->get($document->path)))->toBe($hash)
        ->and($lesson->documents()->sole()->id)->toBe($document->id)
        ->and(app(LessonDocumentLibrary::class)->page($actor)['total'])->toBe(0);
    $html = '<p><strong>Retained</strong> <a href="/lesson-documents/'.$document->public_id.'" rel="noopener noreferrer">Kept</a></p>';
    $editor->set('records.0.content_markdown', $html)->call('saveDraft')->assertHasNoErrors();
    expect($lesson->fresh()->content_markdown)->toBe($html);
    expect($source->fresh()->content_markdown)->toBe($sourceHtml)->and($source->documents()->sole()->id)->toBe($document->id);
    $copy = Lesson::factory()->create(['company_id' => $lesson->company_id, 'is_shared' => $lesson->is_shared, 'content_markdown' => $sourceHtml]);
    app(LessonDocumentLinks::class)->copy($source, $copy);
    app(LessonDocumentLinks::class)->validate($copy, $sourceHtml);
    expect($copy->documents()->sole()->id)->toBe($document->id);
    $revision = $editor->get('revisions');
    expect(fn () => app(ReuseLessonDocument::class)->handle($document->public_id, $fixture->context->name, $fixture->root->id, $lesson->id, $actor, $revision['record:'.$lesson->id] ?? $revision['root']))
        ->toThrow(ValidationException::class, __('This PDF is no longer available for reuse.'));
    expect(fn () => $archive->update(['archived_at' => now()]))->toThrow(LogicException::class);
    expect(fn () => $archive->delete())->toThrow(LogicException::class);
})->with('course editor contexts');

it('rejects archive-first reuse with no attachment and retains old learner delivery', function (): void {
    [$fixture, $editor] = pdfEditor('company course');
    grantPermissions($fixture->user, [Permission::LessonDocumentsReuse, Permission::LessonDocumentsArchive]);
    $document = libraryPdf();
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $source = Lesson::findOrFail($fixture->recordIds[1]);
    $source->documents()->attach($document);
    $source->update(['content_markdown' => '<p><a href="/lesson-documents/'.$document->public_id.'">Old guide</a></p>', 'status' => 'published']);
    $learner = employeeUser();
    $assignment = UserTrainingAssignment::factory()->create(['user_id' => $learner->id, 'course_id' => $fixture->root->id, 'course_version_id' => $lesson->course_version_id]);
    app(ArchiveLessonDocument::class)->handle($document->public_id, $fixture->user);
    $editor->call('openPdfModal', 'records.0.content_markdown', '', 'archive-first')->call('reusePdf', $document->public_id)->assertHasErrors('pdfLibrary')->assertNotDispatched('oceanix:insert-pdf');
    expect($lesson->documents()->count())->toBe(0);
    $url = route('my-training.documents', ['company' => currentCompany(), 'assignment' => $assignment, 'lesson' => $source, 'document' => $document->public_id]);
    expect($this->actingAs($learner)->get($url)->assertOk()->streamedContent())->toBe(Storage::disk($document->disk)->get($document->path));
    $this->actingAs($fixture->user)->get(route('lesson-documents.library.open', ['company' => currentCompany(), 'document' => $document->public_id]))->assertNotFound();
    $copy = Lesson::findOrFail($fixture->recordIds[2]);
    app(LessonDocumentLinks::class)->copy($source, $copy);
    expect($copy->documents()->sole()->id)->toBe($document->id);
});

it('serves a reused saved destination to its assignee before and after archival with unrelated denial', function (): void {
    [$fixture, $editor] = pdfEditor('company course');
    grantPermissions($fixture->user, [Permission::LessonDocumentsReuse, Permission::LessonDocumentsArchive]);
    $document = libraryPdf();
    $source = Lesson::findOrFail($fixture->recordIds[1]);
    $destination = Lesson::findOrFail($fixture->recordIds[0]);
    $html = '<p><a href="/lesson-documents/'.$document->public_id.'" rel="noopener noreferrer">Reused source</a></p>';
    $source->documents()->attach($document);
    $source->update(['content_markdown' => $html]);
    // Refresh the editor revision after preparing the source within the same draft.
    $editor = Livewire\Livewire::test($fixture->context->component, $fixture->routeParameters());
    $editor->call('openPdfModal', 'records.0.content_markdown', 'Reused source', 'delivery')->call('reusePdf', $document->public_id)->assertHasNoErrors();
    $editor->set('records.0.content_markdown', $html)->call('saveDraft')->assertHasNoErrors();
    $learner = employeeUser();
    $unrelated = employeeUser();
    $assignment = UserTrainingAssignment::factory()->create(['user_id' => $learner->id, 'course_id' => $fixture->root->id, 'course_version_id' => $destination->course_version_id]);
    $url = route('my-training.documents', ['company' => currentCompany(), 'assignment' => $assignment, 'lesson' => $destination, 'document' => $document->public_id]);
    $bytes = Storage::disk($document->disk)->get($document->path);
    foreach ([false, true] as $archived) {
        if ($archived) {
            app(ArchiveLessonDocument::class)->handle($document->public_id, $fixture->user);
        }
        expect($this->actingAs($learner)->get($url)->assertOk()->streamedContent())->toBe($bytes);
        $this->actingAs($unrelated)->get($url)->assertForbidden();
        expect($source->fresh()->content_markdown)->toBe($html)->and($source->documents()->sole()->id)->toBe($document->id);
    }
});

it('cancels confirmation without archive or editor mutation and corrects an empty last page', function (): void {
    [$fixture, $editor] = pdfEditor('company course');
    grantPermissions($fixture->user, [Permission::LessonDocumentsArchive]);
    foreach (range(1, 21) as $i) {
        libraryPdf(['name' => 'Document '.$i.'.pdf']);
    }
    $before = $editor->get('records.0.content_markdown');
    $editor->call('openPdfModal', 'records.0.content_markdown', 'Selected text', 'retained-token')->call('loadPdfLibrary', 2);
    $id = $editor->get('pdfLibrary')['items'][0]['id'];
    $editor->call('requestArchivePdf', $id)->assertSet('pdfArchiveModalOpen', true)->call('cancelArchivePdf')->assertSet('pdfArchiveModalOpen', false);
    expect(LessonDocumentArchive::count())->toBe(0)->and($editor->get('pdfOperationToken'))->toBe('retained-token');
    $editor->call('requestArchivePdf', $id)->call('archivePdf')->assertHasNoErrors()->assertSet('pdfArchiveModalOpen', false);
    expect($editor->get('pdfLibrary')['current_page'])->toBe(1)->and($editor->get('pdfLibrary')['total'])->toBe(20)
        ->and($editor->get('records.0.content_markdown'))->toBe($before)->and($editor->get('pdfLinkText'))->toBe('Selected text');
});

it('enforces exactly one archival actor even for direct database writes and prevents destructive rollback', function (): void {
    $document = libraryPdf();
    $actor = adminUser();
    expect(fn () => DB::table('lesson_document_archives')->insert(['lesson_document_id' => $document->id, 'archived_at' => now()]))->toThrow(QueryException::class);
    app(ArchiveLessonDocument::class)->handle($document->public_id, $actor);
    $migration = require database_path('migrations/2026_09_16_120000_create_lesson_document_archives_table.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class);
});
