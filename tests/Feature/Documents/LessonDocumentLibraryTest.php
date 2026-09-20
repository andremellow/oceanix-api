<?php

use App\Actions\Documents\ReuseLessonDocument;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\Lesson;
use App\Models\LessonDocument;
use App\Services\Documents\LessonDocumentLibrary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(fn () => Storage::fake('lesson_documents'));

it('reaches every active document beyond sixty and searches literal filenames', function (): void {
    $user = adminUser();
    $ids = [];
    for ($i = 0; $i < 65; $i++) {
        $ids[] = libraryPdf(['name' => $i === 5 ? 'Literal 10%_! GUIDE.pdf' : 'Duplicate guide.pdf'])->public_id;
    }
    $library = app(LessonDocumentLibrary::class);
    $actual = [];
    for ($page = 1; $page <= 4; $page++) {
        $result = $library->page($user, '', $page);
        $actual = [...$actual, ...array_column($result['items'], 'id')];
        expect($result['total'])->toBe(65)->and($result['last_page'])->toBe(4);
    }
    expect($actual)->toBe(array_reverse($ids));
    expect($library->page($user, '10%_! guide')['total'])->toBe(1)
        ->and($library->page($user, 'absent')['total'])->toBe(0)
        ->and($library->page($user, '', 999)['current_page'])->toBe(4);
});

it('reuses the same bytes in every draft context and saves only explicitly', function (string $context): void {
    [$fixture, $editor] = pdfEditor($context);
    if ($fixture->user) {
        grantPermissions($fixture->user, [Permission::LessonDocumentsReuse]);
    }
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $document = libraryPdf(['company_id' => $lesson->company_id, 'is_shared' => $lesson->is_shared]);
    $source = Lesson::factory()->create(['company_id' => $lesson->company_id, 'is_shared' => $lesson->is_shared, 'content_markdown' => '<p><a href="/lesson-documents/'.$document->public_id.'">Source guide</a></p>']);
    $source->documents()->attach($document);
    $sourceHtml = $source->content_markdown;
    $metadata = $document->fresh()->getAttributes();
    $bytes = Storage::disk($document->disk)->get($document->path);
    $before = $lesson->content_markdown;
    $editor->call('openPdfModal', 'records.0.content_markdown', 'Custom guide', 'reuse-1')
        ->call('reusePdf', $document->public_id)->assertHasNoErrors()->assertDispatched('oceanix:insert-pdf');
    expect($lesson->documents()->sole()->id)->toBe($document->id)
        ->and($lesson->fresh()->content_markdown)->toBe($before)->and(LessonDocument::count())->toBe(1);
    $editor->set('records.0.content_markdown', '<p><a href="/lesson-documents/'.$document->public_id.'">Custom guide</a></p>')->call('saveDraft')->assertHasNoErrors();
    expect($lesson->fresh()->content_markdown)->toContain($document->public_id);
    expect($source->fresh()->content_markdown)->toBe($sourceHtml)
        ->and($source->documents()->sole()->id)->toBe($document->id)
        ->and($document->fresh()->getAttributes())->toBe($metadata)
        ->and(Storage::disk($document->disk)->get($document->path))->toBe($bytes);
    $parameters = ['document' => $document->public_id];
    if ($fixture->context->name === 'company-course') {
        $name = 'courses.lessons.documents';
        $parameters += ['company' => currentCompany(), 'course' => $fixture->root, 'lesson' => $lesson];
    } elseif ($fixture->context->name === 'shared-course') {
        $name = 'platform.shared-courses.documents';
        $version = $fixture->root->versions()->sole();
        $parameters += ['course' => $fixture->root, 'version' => $version, 'kind' => 'composition', 'item' => $version->moduleCompositions()->where('lesson_id', $lesson->id)->sole()->id];
    } else {
        $name = 'platform.shared-modules.documents';
        $parameters += ['module' => $fixture->root];
    }
    $url = route($name, $parameters);
    expect($this->get($url)->assertOk()->streamedContent())->toBe($bytes);
    if ($fixture->user) {
        $this->actingAs(employeeUser())->get($url)->assertForbidden();
    } else {
        $this->withSession(['platform_account_id' => Account::factory()->create()->id])->get($url)->assertRedirect();
    }
})->with('course editor contexts');

it('denies cancelled and published reuse without altering draft or published attachments', function (string $context): void {
    [$fixture, $editor] = pdfEditor($context);
    if ($fixture->user) {
        grantPermissions($fixture->user, [Permission::LessonDocumentsReuse]);
    }
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $document = libraryPdf(['company_id' => $lesson->company_id, 'is_shared' => $lesson->is_shared]);
    $revisions = $editor->get('revisions');
    $editor->call('openPdfModal', 'records.0.content_markdown', 'Cancelled', 'cancelled')->set('pdfModalOpen', false)->call('reusePdf', $document->public_id)->assertStatus(422);
    expect($lesson->documents()->count())->toBe(0);
    $actor = $fixture->user ?? Account::findOrFail($fixture->session['platform_account_id']);
    $before = $lesson->content_markdown;
    $lesson->update(['status' => 'published']);
    try {
        app(ReuseLessonDocument::class)->handle($document->public_id, $fixture->context->name, $fixture->root->id, $lesson->id, $actor, $revisions['record:'.$lesson->id] ?? $revisions['root']);
        test()->fail('Published target accepted a new attachment.');
    } catch (ModelNotFoundException $exception) {
        expect($context)->not->toBe('company course');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
    expect($lesson->documents()->count())->toBe(0)->and($lesson->fresh()->content_markdown)->toBe($before);
})->with('course editor contexts');

it('rejects stale revisions without creating attachments in every context', function (string $context): void {
    [$fixture] = pdfEditor($context);
    if ($fixture->user) {
        grantPermissions($fixture->user, [Permission::LessonDocumentsReuse]);
    }
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $document = libraryPdf(['company_id' => $lesson->company_id, 'is_shared' => $lesson->is_shared]);
    $actor = $fixture->user ?? Account::findOrFail($fixture->session['platform_account_id']);
    expect(fn () => app(ReuseLessonDocument::class)->handle($document->public_id, $fixture->context->name, $fixture->root->id, $lesson->id, $actor, 'stale'))
        ->toThrow(ValidationException::class);
    expect($lesson->documents()->count())->toBe(0);
})->with('course editor contexts');
