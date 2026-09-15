<?php

use App\Actions\Documents\UploadLessonDocument;
use App\Enums\CourseVersionStatus;
use App\Livewire\CourseEditor\Contexts\CompanyCourseEditorContext;
use App\Livewire\CourseEditor\Contexts\SharedCourseEditorContext;
use App\Livewire\CourseEditor\Contexts\SharedModuleEditorContext;
use App\Models\Account;
use App\Models\Company;
use App\Models\Course;
use App\Models\CoursePreviewLink;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\LessonDocument;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Models\Video;
use App\Services\CourseEditor\EditorSaveCommand;
use App\Services\CourseEditor\EditorSnapshotBuilder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(fn () => Storage::fake('lesson_documents'));

function attachedEvidencePdf(Lesson $lesson, string $name = 'Evidence'): LessonDocument
{
    $uuid = (string) Str::uuid();
    $bytes = file_get_contents(base_path('tests/Fixtures/lesson-guide.pdf'));
    $document = LessonDocument::create(['public_id' => $uuid, 'company_id' => $lesson->company_id, 'is_shared' => $lesson->is_shared, 'name' => $name.'.pdf', 'disk' => 'lesson_documents', 'path' => $uuid.'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => strlen($bytes)]);
    Storage::disk('lesson_documents')->put($document->path, $bytes);
    $lesson->documents()->attach($document);
    $lesson->update(['content_markdown' => '<p><a href="/lesson-documents/'.$uuid.'">'.$name.'</a></p>']);

    return $document;
}

function pdfPageAnchors(string $html): array
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $anchors = [];
    foreach ($dom->getElementsByTagName('a') as $anchor) {
        if (str_contains($anchor->getAttribute('href'), '/documents/')) {
            $anchors[] = ['href' => $anchor->getAttribute('href'), 'target' => $anchor->getAttribute('target'), 'rel' => $anchor->getAttribute('rel')];
        }
    }

    return $anchors;
}

it('TA-01 requires an actual association even with a saved anchor and checks assignment course tenant and anonymous boundaries', function (): void {
    [$fixture] = pdfEditor('company course');
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $document = attachedEvidencePdf($lesson, 'Own PDF');
    $otherCourse = Course::factory()->create();
    $otherVersion = CourseVersion::factory()->create(['course_id' => $otherCourse]);
    $foreign = Lesson::factory()->create(['course_version_id' => $otherVersion]);
    $foreignPdf = attachedEvidencePdf($foreign, 'Foreign PDF');
    $learner = employeeUser();
    $assignment = UserTrainingAssignment::factory()->create(['user_id' => $learner, 'course_id' => $fixture->root, 'course_version_id' => $lesson->course_version_id]);
    $foreignAssignment = UserTrainingAssignment::factory()->create(['user_id' => $learner, 'course_id' => $otherCourse, 'course_version_id' => $otherVersion]);
    $url = fn ($a, $l, $d) => route('my-training.documents', ['company' => currentCompany(), 'assignment' => $a, 'lesson' => $l, 'document' => $d->public_id]);
    $this->actingAs($learner)->get($url($assignment, $lesson, $document))->assertOk();
    $this->get($url($foreignAssignment, $foreign, $foreignPdf))->assertOk();
    $lesson->update(['content_markdown' => $lesson->content_markdown.$foreign->content_markdown]);
    $this->get($url($assignment, $lesson, $foreignPdf))->assertNotFound();
    $this->get($url($assignment, $foreign, $foreignPdf))->assertNotFound();
    $authorUrl = fn ($c, $l) => route('courses.lessons.documents', ['company' => currentCompany(), 'course' => $c, 'lesson' => $l, 'document' => $foreignPdf->public_id]);
    $this->actingAs($fixture->user)->get($authorUrl($otherCourse, $foreign))->assertOk();
    $this->get($authorUrl($fixture->root, $foreign))->assertNotFound();
    $otherCompany = Company::factory()->create();
    $outsider = User::factory()->create(['company_id' => $otherCompany->id]);
    $this->actingAs($outsider)->get($url($assignment, $lesson, $document))->assertNotFound();
    auth()->logout();
    $this->get($url($assignment, $lesson, $document))->assertRedirect();
    $this->get('/lesson-documents/'.$document->public_id)->assertNotFound();
});

it('TA-01 rechecks previously authorized author reads in every editor context', function (string $context): void {
    [$fixture] = pdfEditor($context);
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $document = attachedEvidencePdf($lesson);
    $version = $fixture->context->name === 'shared-module' ? null : $fixture->root->versions()->sole();
    $url = match ($fixture->context->name) {
        'company-course' => route('courses.lessons.documents', ['company' => currentCompany(), 'course' => $fixture->root, 'lesson' => $lesson, 'document' => $document->public_id]),
        'shared-course' => route('platform.shared-courses.documents', ['course' => $fixture->root, 'version' => $version, 'kind' => 'composition', 'item' => $version->moduleCompositions()->where('lesson_id', $lesson->id)->sole()->id, 'document' => $document->public_id]),
        'shared-module' => route('platform.shared-modules.documents', ['module' => $fixture->root, 'document' => $document->public_id]),
    };
    $this->get($url)->assertOk();
    if ($fixture->user) {
        $fixture->user->roles()->detach();
    } else {
        Account::findOrFail($fixture->session['platform_account_id'])->update(['status' => 'suspended']);
    }
    $denied = $this->get($url);
    if ($fixture->user) {
        $denied->assertForbidden();
    } else {
        $denied->assertRedirect(route('platform.login'));
    }
})->with('course editor contexts');

it('TA-01 refuses saving an existing PDF from a different lesson in each context', function (string $context): void {
    [$fixture, $editor] = pdfEditor($context);
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $foreign = Lesson::factory()->create(['course_version_id' => null, 'company_id' => $lesson->company_id, 'is_shared' => $lesson->is_shared]);
    $document = attachedEvidencePdf($foreign);
    $before = $lesson->content_markdown;
    $editor->set('records.0.content_markdown', $foreign->content_markdown)->call('saveDraft')->assertSet('saveState', 'validation-error');
    expect($lesson->fresh()->content_markdown)->toBe($before)->and($lesson->documents()->count())->toBe(0)->and($foreign->documents()->sole()->id)->toBe($document->id);
    Storage::disk('lesson_documents')->assertExists($document->path);
})->with('course editor contexts');

it('TA-01 allows a genuinely shared PDF lesson in an assigned company course', function (): void {
    [$fixture] = pdfEditor('standalone shared module');
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $document = attachedEvidencePdf($lesson);
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course]);
    CourseVersionModule::create(['course_version_id' => $version->id, 'lesson_id' => $lesson->id, 'position' => 1, 'is_required' => true]);
    $learner = employeeUser();
    $assignment = UserTrainingAssignment::factory()->create(['user_id' => $learner, 'course_id' => $course, 'course_version_id' => $version]);
    expect($document->company_id)->toBeNull();
    $this->actingAs($learner)->get(route('my-training.documents', ['company' => currentCompany(), 'assignment' => $assignment, 'lesson' => $lesson, 'document' => $document->public_id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('TA-02 renders contextual new-tab links in each preview including both sides of video', function (string $context): void {
    [$fixture] = pdfEditor($context);
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $first = attachedEvidencePdf($lesson, 'Before PDF');
    $second = attachedEvidencePdf($lesson, 'After PDF');
    $lesson->update(['content_markdown' => '<p><a href="/lesson-documents/'.$first->public_id.'">Before PDF</a></p><div data-oceanix-video></div><p><a href="/lesson-documents/'.$second->public_id.'">After PDF</a></p>']);
    Video::factory()->create(['lesson_id' => $lesson->id, 'company_id' => $lesson->company_id]);
    $version = $fixture->context->name === 'shared-module' ? null : $fixture->root->versions()->sole();
    $url = match ($fixture->context->name) {
        'company-course' => route('courses.lessons.preview', ['company' => currentCompany(), 'course' => $fixture->root, 'lesson' => $lesson]),
        'shared-course' => route('platform.shared-courses.preview', ['course' => $fixture->root, 'version' => $version, 'kind' => 'composition', 'item' => $version->moduleCompositions()->where('lesson_id', $lesson->id)->sole()->id]),
        'shared-module' => route('platform.shared-modules.preview', ['module' => $fixture->root]),
    };
    $response = $this->get($url)->assertOk();
    $anchors = pdfPageAnchors($response->getContent());
    expect($anchors)->toHaveCount(2);
    foreach ($anchors as $anchor) {
        expect($anchor['target'])->toBe('_blank')->and($anchor['rel'])->toContain('noopener', 'noreferrer');
        $this->get($anchor['href'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
    if ($context === 'company course') {
        $link = CoursePreviewLink::factory()->create(['course_version_id' => $version]);
        $item = $version->moduleCompositions()->where('lesson_id', $lesson->id)->sole()->id;
        $preview = $this->get(route('course-preview.item', ['token' => $link->token_encrypted, 'kind' => 'composition', 'item' => $item]))->assertOk();
        $tokenAnchors = pdfPageAnchors($preview->getContent());
        expect($tokenAnchors)->toHaveCount(2);
        foreach ($tokenAnchors as $anchor) {
            expect($anchor['href'])->toContain('/preview/courses/')->and($anchor['target'])->toBe('_blank');
            $this->get($anchor['href'])->assertOk();
        }
        $learner = employeeUser();
        $assignment = UserTrainingAssignment::factory()->create(['user_id' => $learner, 'course_id' => $fixture->root, 'course_version_id' => $version]);
        $page = $this->actingAs($learner)->get(route('my-training.lesson', ['company' => currentCompany(), 'assignment' => $assignment, 'lesson' => $lesson]))->assertOk();
        $learnerAnchors = pdfPageAnchors($page->getContent());
        expect($learnerAnchors)->toHaveCount(2);
        foreach ($learnerAnchors as $anchor) {
            expect($anchor['href'])->toContain('/my-training/')->and($anchor['target'])->toBe('_blank');
            $this->get($anchor['href'])->assertOk();
        }
    }
})->with('course editor contexts');

it('TA-04 cleans only the uncommitted file after transfer and accepts exactly ten MB', function (): void {
    [$fixture, $editor] = pdfEditor('company course');
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $existing = attachedEvidencePdf($lesson);
    $beforeHtml = $lesson->content_markdown;
    $beforeBytes = Storage::disk('lesson_documents')->get($existing->path);
    expect(fn () => app(UploadLessonDocument::class)->handle(lessonPdfUpload(), 'company-course', $fixture->root->id, $lesson->id, $fixture->user, 'stale-revision'))->toThrow(ValidationException::class);
    expect(LessonDocument::count())->toBe(1)->and($lesson->documents()->count())->toBe(1)->and($lesson->fresh()->content_markdown)->toBe($beforeHtml);
    expect(Storage::disk('lesson_documents')->allFiles())->toBe([$existing->path])->and(Storage::disk('lesson_documents')->get($existing->path))->toBe($beforeBytes);
    $snapshot = app(EditorSnapshotBuilder::class)->forCompanyCourse($fixture->root->id, $fixture->user);
    $bytes = str_pad(file_get_contents(base_path('tests/Fixtures/lesson-guide.pdf')), 10240 * 1024, ' ');
    $upload = UploadedFile::fake()->createWithContent('boundary.pdf', $bytes)->mimeType('application/pdf');
    app(UploadLessonDocument::class)->handle($upload, 'company-course', $fixture->root->id, $lesson->id, $fixture->user, $snapshot->revisions['root']);
    expect(LessonDocument::count())->toBe(2)->and(LessonDocument::latest('id')->first()->size_bytes)->toBe(10240 * 1024);
});

it('TA-05 refuses PDF upload and staged save against published records in all contexts', function (string $context): void {
    [$fixture] = pdfEditor($context);
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $document = attachedEvidencePdf($lesson);
    $adapter = app(match ($fixture->context->name) {
        'company-course' => CompanyCourseEditorContext::class,
        'shared-course' => SharedCourseEditorContext::class,
        'shared-module' => SharedModuleEditorContext::class,
    });
    $snapshot = $adapter->open($fixture->root->id);
    $records = $snapshot->records;
    $records[0]['content_markdown'] = '<p>attempted published overwrite</p>';
    $command = new EditorSaveCommand($snapshot->course, $snapshot->version, $records, $snapshot->revisions, 1, [$records[0]['key']]);
    $lesson->update(['status' => 'published']);
    if ($fixture->context->name !== 'shared-module') {
        $fixture->root->versions()->update(['status' => CourseVersionStatus::Published->value]);
    }
    $html = $lesson->fresh()->content_markdown;
    $bytes = Storage::disk('lesson_documents')->get($document->path);
    foreach ([fn () => $adapter->performMedia($fixture->root->id, 'upload-pdf', ['upload' => lessonPdfUpload(), 'record_id' => $lesson->id, 'revision' => $snapshot->revisions['root']]), fn () => $adapter->save($fixture->root->id, $command)] as $attempt) {
        try {
            $attempt();
            test()->fail('Published PDF operation was accepted.');
        } catch (ModelNotFoundException|HttpException $exception) {
            expect($exception)->not->toBeNull();
        }
        expect($lesson->fresh()->content_markdown)->toBe($html)->and(LessonDocument::count())->toBe(1)->and($lesson->documents()->count())->toBe(1)->and(Storage::disk('lesson_documents')->get($document->path))->toBe($bytes);
    }
})->with('course editor contexts');

it('TA-03 renders the PDF controls and failure text in Portuguese', function (): void {
    app()->setLocale('pt_BR');
    [$fixture, $editor] = pdfEditor('company course');
    $editor->assertSee('Inserir PDF')->assertSee('Arquivo PDF')->assertSee('Enviar e inserir link');
    $editor->call('openPdfModal', 'records.0.content_markdown', '', 'locale')->set('pdfUpload', lessonPdfUpload());
    $failing = Mockery::mock(FilesystemAdapter::class);
    $failing->shouldReceive('putFileAs')->andThrow(new RuntimeException('simulated storage failure'));
    Storage::set('lesson_documents', $failing);
    $editor->call('uploadPdf')->assertHasErrors('pdfUpload')->assertSee('Não foi possível enviar o PDF. Tente novamente.');
});
