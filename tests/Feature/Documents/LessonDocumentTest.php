<?php

use App\Actions\Courses\CreateDraftFromVersion;
use App\Actions\Documents\UploadLessonDocument;
use App\Actions\Modules\CreateModuleDraft;
use App\Models\Account;
use App\Models\CoursePreviewLink;
use App\Models\Lesson;
use App\Models\LessonDocument;
use App\Models\ModuleVersion;
use App\Models\UserTrainingAssignment;
use App\Services\Documents\LessonDocumentLinks;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;

function pdfEditor(string $context): array
{
    $fixture = EditorFixture::create(EditorContextCase::all()[$context]);
    Lesson::whereKey($fixture->recordIds)->update(['content_markdown' => '<p><strong>Retained author text</strong> with <a href="https://example.com/guide">existing guide</a>.</p>']);
    if ($fixture->user) {
        Livewire::actingAs($fixture->user);
    }
    test()->withSession($fixture->session);

    return [$fixture, Livewire::test($fixture->context->component, $fixture->routeParameters())];
}

function lessonPdfUpload(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('Safety guide.pdf', file_get_contents(base_path('tests/Fixtures/lesson-guide.pdf')))->mimeType('application/pdf');
}

beforeEach(fn () => Storage::fake('lesson_documents'));

it('uploads privately in each editor and saves a canonical link without losing formatting', function (string $context): void {
    [$fixture, $editor] = pdfEditor($context);
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $before = $lesson->content_markdown;
    $editor->call('openPdfModal', 'records.0.content_markdown', 'Read guide', 'operation-1')
        ->set('pdfUpload', lessonPdfUpload())->call('uploadPdf')->assertHasNoErrors()->assertDispatched('oceanix:insert-pdf');
    $document = LessonDocument::sole();
    expect($document->disk)->toBe('lesson_documents')->and($lesson->fresh()->content_markdown)->toBe($before)
        ->and($lesson->documents()->count())->toBe(1);
    Storage::disk('lesson_documents')->assertExists($document->path);
    $html = '<p><strong>Before</strong> <a href="/lesson-documents/'.$document->public_id.'" target="_blank" rel="noopener noreferrer">Read guide</a> after <a href="https://example.com">ordinary</a></p>';
    $editor->set('records.0.content_markdown', $html)->call('saveDraft')->assertHasNoErrors();
    expect($lesson->fresh()->content_markdown)->toContain('Read guide', '<strong>Before</strong>', 'https://example.com', '/lesson-documents/'.$document->public_id);
    $editor->set('records.0.content_markdown', '<p>Removed link</p>')->call('saveDraft')->assertHasNoErrors();
    expect($lesson->documents()->count())->toBe(1);
    Storage::disk('lesson_documents')->assertExists($document->path);
})->with('course editor contexts');

it('rejects invalid and oversized files without inserting or changing text and permits retry', function (string $context): void {
    [$fixture, $editor] = pdfEditor($context);
    $before = $editor->get('records.0.content_markdown');
    $editor->call('openPdfModal', 'records.0.content_markdown', '', 'failure-1')
        ->set('pdfUpload', UploadedFile::fake()->createWithContent('fake.pdf', 'not a PDF')->mimeType('text/plain'))->call('uploadPdf')
        ->assertHasErrors('pdfUpload')->assertNotDispatched('oceanix:insert-pdf');
    expect(LessonDocument::count())->toBe(0)->and($editor->get('records.0.content_markdown'))->toBe($before);
    $editor->set('pdfUpload', UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'))->call('uploadPdf')->assertHasErrors('pdfUpload');
    expect($editor->get('records.0.content_markdown'))->toBe($before)->and(LessonDocument::count())->toBe(0);
    $editor->set('pdfUpload', lessonPdfUpload())->call('uploadPdf')->assertHasNoErrors()->assertDispatched('oceanix:insert-pdf');
})->with('course editor contexts');

it('rejects unattached canonical references on save', function (string $context): void {
    [$fixture, $editor] = pdfEditor($context);
    $before = Lesson::findOrFail($fixture->recordIds[0])->content_markdown;
    $editor->set('records.0.content_markdown', '<p><a href="/lesson-documents/11111111-1111-4111-8111-111111111111">forged</a></p>')->call('saveDraft');
    expect(Lesson::findOrFail($fixture->recordIds[0])->content_markdown)->toBe($before);
    expect($editor->get('saveState'))->toBe('validation-error');
})->with('course editor contexts');

it('serves only saved associated PDFs to the assignee and rechecks revoked access', function (): void {
    [$fixture, $editor] = pdfEditor('company course');
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $editor->call('openPdfModal', 'records.0.content_markdown', '', 'read-1')->set('pdfUpload', lessonPdfUpload())->call('uploadPdf')->assertHasNoErrors();
    $document = LessonDocument::sole();
    $learner = employeeUser();
    $assignment = UserTrainingAssignment::factory()->create(['user_id' => $learner->id, 'course_id' => $fixture->root->id, 'course_version_id' => $lesson->course_version_id]);
    $url = route('my-training.documents', ['company' => currentCompany(), 'assignment' => $assignment, 'lesson' => $lesson, 'document' => $document->public_id]);
    $this->actingAs($learner)->get($url)->assertNotFound();
    $lesson->update(['content_markdown' => '<p><a href="/lesson-documents/'.$document->public_id.'">Guide</a></p>']);
    $response = $this->actingAs($learner)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')->and($response->streamedContent())->toStartWith('%PDF-');
    $this->actingAs(employeeUser())->get($url)->assertForbidden();
    $this->actingAs($fixture->user)->get($url)->assertForbidden();
    $assignment->update(['status' => 'cancelled']);
    $this->actingAs($learner)->get($url)->assertForbidden();
    $this->get('/lesson-documents/'.$document->public_id)->assertNotFound();
});

it('maps only canonical references and preserves ordinary links', function (): void {
    $id = '11111111-1111-4111-8111-111111111111';
    $html = '<p><a href="/lesson-documents/'.$id.'">PDF</a><a href="https://example.com/lesson-documents/'.$id.'">ordinary</a></p>';
    $mapped = app(LessonDocumentLinks::class)->map($html, fn ($uuid) => '/authorized/'.$uuid);
    expect($mapped)->toContain('/authorized/'.$id, 'target="_blank"', 'rel="noopener noreferrer"', 'https://example.com/lesson-documents/'.$id);
    expect(app(LessonDocumentLinks::class)->ids('<a href="/lesson-documents/'.$id.'?x=1">query</a>'))->toBe([]);
});

it('rechecks author permissions and editable state before upload', function (string $context): void {
    [$fixture, $editor] = pdfEditor($context);
    $editor->call('openPdfModal', 'records.0.content_markdown', '', 'revoked');
    if ($fixture->user) {
        $fixture->user->roles()->detach();
    } else {
        Account::findOrFail($fixture->session['platform_account_id'])->update(['status' => 'suspended']);
    }
    $editor->set('pdfUpload', lessonPdfUpload())->call('uploadPdf')->assertForbidden();
    expect(LessonDocument::count())->toBe(0);
})->with('course editor contexts');

it('preserves text and reports a storage failure before permitting a successful retry', function (): void {
    [$fixture, $editor] = pdfEditor('company course');
    $editor->call('openPdfModal', 'records.0.content_markdown', '', 'storage-failure')->set('pdfUpload', lessonPdfUpload());
    $original = Storage::disk('lesson_documents');
    $failing = Mockery::mock(FilesystemAdapter::class);
    $failing->shouldReceive('putFileAs')->andThrow(new RuntimeException('simulated storage failure'));
    Storage::set('lesson_documents', $failing);
    $before = $editor->get('records.0.content_markdown');
    $editor->call('uploadPdf')->assertHasErrors('pdfUpload')->assertNotDispatched('oceanix:insert-pdf');
    expect($editor->get('records.0.content_markdown'))->toBe($before)->and(LessonDocument::count())->toBe(0);
    Storage::set('lesson_documents', $original);
    $editor->call('uploadPdf')->assertHasNoErrors()->assertDispatched('oceanix:insert-pdf');
});

it('authorizes all author preview contexts with positive and unrelated document controls', function (string $context): void {
    [$fixture, $editor] = pdfEditor($context);
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $editor->call('openPdfModal', 'records.0.content_markdown', '', 'preview')->set('pdfUpload', lessonPdfUpload())->call('uploadPdf')->assertHasNoErrors();
    $document = LessonDocument::sole();
    $lesson->update(['content_markdown' => '<p><a href="/lesson-documents/'.$document->public_id.'">Guide</a></p>']);
    $parameters = ['document' => $document->public_id];
    $name = match ($fixture->context->name) {
        'company-course' => 'courses.lessons.documents',
        'shared-course' => 'platform.shared-courses.documents',
        'shared-module' => 'platform.shared-modules.documents',
    };
    if ($fixture->context->name === 'company-course') {
        $parameters += ['company' => currentCompany(), 'course' => $fixture->root, 'lesson' => $lesson];
    } elseif ($fixture->context->name === 'shared-course') {
        $version = $fixture->root->versions()->sole();
        $parameters += ['course' => $fixture->root, 'version' => $version, 'kind' => 'composition', 'item' => $version->moduleCompositions()->where('lesson_id', $lesson->id)->sole()->id];
    } else {
        $parameters += ['module' => $fixture->root];
    }
    $this->get(route($name, $parameters))->assertOk();
    $parameters['document'] = '11111111-1111-4111-8111-111111111111';
    $this->get(route($name, $parameters))->assertNotFound();
})->with('course editor contexts');

it('checks anonymous token preview on every request including expiry', function (): void {
    [$fixture, $editor] = pdfEditor('company course');
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $editor->call('openPdfModal', 'records.0.content_markdown', '', 'token-preview')->set('pdfUpload', lessonPdfUpload())->call('uploadPdf');
    $document = LessonDocument::sole();
    $lesson->update(['content_markdown' => '<p><a href="/lesson-documents/'.$document->public_id.'">Guide</a></p>']);
    $link = CoursePreviewLink::factory()->create(['course_version_id' => $lesson->course_version_id]);
    $url = route('course-preview.documents', ['token' => $link->token_encrypted, 'kind' => 'composition', 'item' => $lesson->courseVersion->moduleCompositions()->where('lesson_id', $lesson->id)->sole()->id, 'document' => $document->public_id]);
    auth()->logout();
    $this->get($url)->assertOk();
    $this->travel(8)->days();
    $this->get($url)->assertStatus(410);
});

it('retains company and shared PDF associations across new drafts and denies published uploads', function (string $context): void {
    [$fixture, $editor] = pdfEditor($context);
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $editor->call('openPdfModal', 'records.0.content_markdown', '', 'copy')->set('pdfUpload', lessonPdfUpload())->call('uploadPdf');
    $document = LessonDocument::sole();
    $html = '<p><a href="/lesson-documents/'.$document->public_id.'">Guide</a></p>';
    $lesson->update(['content_markdown' => $html, 'status' => 'published']);
    if ($fixture->context->name !== 'company-course') {
        expect(fn () => app(UploadLessonDocument::class)->handle(lessonPdfUpload(), $fixture->context->name, $fixture->root->id, $lesson->id, Account::findOrFail($fixture->session['platform_account_id']), 'published'))->toThrow(ModelNotFoundException::class);
    }
    if ($fixture->context->name === 'company-course') {
        $version = $lesson->courseVersion;
        // The legacy direct-copy path has no composition mirrors.
        $version->moduleCompositions()->delete();
        Lesson::where('course_version_id', $version->id)->update(['status' => 'published']);
        $version->update(['status' => 'published']);
        $draft = app(CreateDraftFromVersion::class)->handle($version);
        $copy = $draft->lessons()->where('source_lesson_id', $lesson->id)->sole();
    } else {
        $copy = app(CreateModuleDraft::class)->handle(ModuleVersion::findOrFail($lesson->id), Account::findOrFail($fixture->session['platform_account_id']));
    }
    expect($copy->content_markdown)->toBe($html)->and($copy->documents()->sole()->id)->toBe($document->id)->and($lesson->fresh()->content_markdown)->toBe($html);
    Storage::disk('lesson_documents')->assertExists($document->path);
    expect(fn () => $document->update(['path' => 'changed.pdf']))->toThrow(LogicException::class);
    expect(fn () => $document->delete())->toThrow(LogicException::class);
})->with(['company course', 'standalone shared module']);
