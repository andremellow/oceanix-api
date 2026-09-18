<?php

use App\Actions\Documents\ArchiveLessonDocument;
use App\Actions\Documents\ReuseLessonDocument;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\Company;
use App\Models\Lesson;
use App\Models\LessonDocument;
use App\Models\LessonDocumentArchive;
use App\Services\Documents\LessonDocumentLibrary;
use App\Services\Documents\LessonDocumentLibraryAccess;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function libraryPdf(array $attributes = []): LessonDocument
{
    $uuid = (string) Str::uuid();
    Storage::disk('lesson_documents')->put($uuid.'.pdf', file_get_contents(base_path('tests/Fixtures/lesson-guide.pdf')));

    return LessonDocument::create([...['public_id' => $uuid, 'company_id' => currentCompany()->id, 'is_shared' => false, 'name' => 'Library guide.pdf', 'disk' => 'lesson_documents', 'path' => $uuid.'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 500], ...$attributes]);
}

beforeEach(fn () => Storage::fake('lesson_documents'));

it('renders archive revocation before and after confirmation without losing insertion state', function (bool $confirmed, bool $viewRevoked): void {
    [$fixture, $editor] = pdfEditor('company course');
    $profile = grantPermissions($fixture->user, [Permission::LessonDocumentsArchive]);
    $document = libraryPdf();
    $before = $editor->get('records.0.content_markdown');
    $editor->call('openPdfModal', 'records.0.content_markdown', 'Preserved label', 'preserved-token');
    if ($confirmed) {
        $editor->call('requestArchivePdf', $document->public_id)->assertSet('pdfArchiveModalOpen', true);
    }
    $profile->permissions()->detach(App\Models\Permission::where('key', $viewRevoked ? Permission::LessonDocumentsView->value : Permission::LessonDocumentsArchive->value)->value('id'));
    $editor->call($confirmed ? 'archivePdf' : 'requestArchivePdf', ...($confirmed ? [] : [$document->public_id]))
        ->assertStatus(200)->assertSet('pdfArchiveModalOpen', false)->assertSet('pdfArchiveConfirmation', null)
        ->assertSet('pdfLinkText', 'Preserved label')->assertSet('pdfOperationToken', 'preserved-token')
        ->assertSet('records.0.content_markdown', $before);
    expect(LessonDocumentArchive::count())->toBe(0);
    if ($viewRevoked) {
        expect($editor->get('pdfLibrary'))->toBe([]);
    } else {
        expect($editor->get('pdfLibrary')['items'][0]['can_archive'])->toBeFalse();
    }
})->with([false, true])->with([false, true]);

it('grants library view through a profile and revokes it on the next request', function (): void {
    $document = libraryPdf();
    $user = userWithPermissions([Permission::LessonDocumentsView]);
    $url = route('lesson-documents.library.open', ['company' => currentCompany(), 'document' => $document->public_id]);
    $this->actingAs($user)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(app(LessonDocumentLibrary::class)->page($user)['total'])->toBe(1);
    $user->roles()->detach();
    $this->get($url)->assertForbidden();
    expect(fn () => app(LessonDocumentLibrary::class)->page($user))->toThrow(AuthorizationException::class);
});

it('keeps the admin bypass inside its owner and never grants learners the catalog', function (): void {
    $own = libraryPdf();
    $company = currentCompany();
    $foreign = libraryPdf(['company_id' => Company::factory()->create()->id]);
    app(TenantContext::class)->set($company);
    $platform = libraryPdf(['company_id' => null, 'is_shared' => true]);
    $admin = adminUser();
    $this->actingAs($admin);
    expect(array_column(app(LessonDocumentLibrary::class)->page($admin)['items'], 'id'))->toBe([$own->public_id]);
    foreach ([$foreign, $platform] as $document) {
        $this->get(route('lesson-documents.library.open', ['company' => currentCompany(), 'document' => $document->public_id]))->assertNotFound();
    }
    $this->actingAs(employeeUser())->get(route('lesson-documents.library.open', ['company' => currentCompany(), 'document' => $own->public_id]))->assertForbidden();
});

it('persists explicit prerequisites without granting unrelated library actions', function (): void {
    $user = userWithPermissions([Permission::LessonDocumentsReuse]);
    expect($user->can(Permission::LessonDocumentsView->value))->toBeTrue()
        ->and($user->can(Permission::CoursesUpdate->value))->toBeTrue()
        ->and($user->can(Permission::CoursesView->value))->toBeTrue()
        ->and($user->can(Permission::LessonDocumentsArchive->value))->toBeFalse();
});

it('enforces independent write grants and runtime prerequisites on actual actions', function (string $removed): void {
    [$fixture, $editor] = pdfEditor('company course');
    $profile = grantPermissions($fixture->user, [Permission::LessonDocumentsReuse, Permission::LessonDocumentsArchive]);
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $source = libraryPdf();
    $control = libraryPdf();
    $revision = $editor->get('revisions')['root'];
    $reuse = fn ($document) => app(ReuseLessonDocument::class)->handle($document->public_id, $fixture->context->name, $fixture->root->id, $lesson->id, $fixture->user, $revision);
    $reuse($control);
    app(ArchiveLessonDocument::class)->handle($control->public_id, $fixture->user);
    $html = $lesson->fresh()->content_markdown;
    // Deliberately incomplete grants bypass catalog prerequisite expansion, exercising runtime enforcement.
    foreach ($fixture->user->roles as $role) {
        $role->permissions()->detach(App\Models\Permission::where('key', $removed)->value('id'));
    }
    if ($removed !== Permission::LessonDocumentsArchive->value) {
        expect(fn () => $reuse($source))->toThrow(AuthorizationException::class);
        expect($lesson->documents()->whereKey($source->id)->exists())->toBeFalse();
    } else {
        $reuse($source);
        expect($lesson->documents()->whereKey($source->id)->exists())->toBeTrue();
    }
    if (in_array($removed, [Permission::LessonDocumentsArchive->value, Permission::LessonDocumentsView->value], true)) {
        expect(fn () => app(ArchiveLessonDocument::class)->handle($source->public_id, $fixture->user))->toThrow(AuthorizationException::class);
        expect($source->archive()->exists())->toBeFalse();
    } else {
        app(ArchiveLessonDocument::class)->handle($source->public_id, $fixture->user);
        expect($source->archive()->exists())->toBeTrue();
    }
    expect($lesson->fresh()->content_markdown)->toBe($html);
})->with([Permission::LessonDocumentsReuse->value, Permission::LessonDocumentsArchive->value, Permission::LessonDocumentsView->value, Permission::CoursesUpdate->value]);

it('checks all owner boundaries for open reuse and archive in each editor', function (string $context): void {
    [$fixture, $editor] = pdfEditor($context);
    if ($fixture->user) {
        grantPermissions($fixture->user, [Permission::LessonDocumentsReuse, Permission::LessonDocumentsArchive]);
    }
    $actor = $fixture->user ?? Account::findOrFail($fixture->session['platform_account_id']);
    $lesson = Lesson::findOrFail($fixture->recordIds[0]);
    $company = currentCompany();
    $otherCompany = Company::factory()->create();
    app(TenantContext::class)->set($company);
    $companyFile = libraryPdf();
    $foreignFile = libraryPdf(['company_id' => $otherCompany->id]);
    $platformFile = libraryPdf(['company_id' => null, 'is_shared' => true]);
    $own = $fixture->user ? $companyFile : $platformFile;
    $foreign = $fixture->user ? [$foreignFile, $platformFile] : [$foreignFile, $companyFile];
    $access = app(LessonDocumentLibraryAccess::class);
    expect($access->open($actor, $own->public_id)->getStatusCode())->toBe(200);
    $revisions = $editor->get('revisions');
    foreach ($foreign as $document) {
        expect(fn () => $access->open($actor, $document->public_id))->toThrow(ModelNotFoundException::class);
        expect(fn () => app(ArchiveLessonDocument::class)->handle($document->public_id, $actor))->toThrow(ModelNotFoundException::class);
        expect(fn () => app(ReuseLessonDocument::class)->handle($document->public_id, $fixture->context->name, $fixture->root->id, $lesson->id, $actor, $revisions['record:'.$lesson->id] ?? $revisions['root']))->toThrow(ModelNotFoundException::class);
    }
    expect($lesson->documents()->count())->toBe(0)->and(LessonDocumentArchive::count())->toBe(0);
    if (! $fixture->user) {
        $this->get(route('platform.lesson-documents.library.open', ['document' => $own->public_id]))->assertOk();
        $this->get(route('platform.lesson-documents.library.open', ['document' => $companyFile->public_id]))->assertNotFound();
    }
})->with('course editor contexts');

it('denies new writes after profile archival and actor deactivation without exposing metadata', function (): void {
    [$fixture, $editor] = pdfEditor('company course');
    $profile = grantPermissions($fixture->user, [Permission::LessonDocumentsReuse, Permission::LessonDocumentsArchive]);
    $document = libraryPdf();
    $editor->call('openPdfModal', 'records.0.content_markdown', 'Preserved', 'revoked-library');
    expect(count($editor->get('pdfLibrary')['items']))->toBe(1);
    $profile->update(['archived_at' => now()]);
    $editor->call('reusePdf', $document->public_id)->assertHasErrors('pdfLibrary')->assertNotDispatched('oceanix:insert-pdf');
    expect($editor->get('pdfLibrary'))->toBe([])->and($editor->get('pdfLinkText'))->toBe('Preserved');
    expect(fn () => app(ArchiveLessonDocument::class)->handle($document->public_id, $fixture->user))->toThrow(AuthorizationException::class);
    $admin = adminUser();
    $admin->update(['status' => 'suspended']);
    expect(fn () => app(LessonDocumentLibrary::class)->page($admin))->toThrow(HttpException::class);
    expect(LessonDocumentArchive::count())->toBe(0);
});

it('returns controlled missing bytes and rejects anonymous and inactive platform access', function (): void {
    $document = libraryPdf();
    $url = route('lesson-documents.library.open', ['company' => currentCompany(), 'document' => $document->public_id]);
    $this->get($url)->assertRedirect();
    Storage::disk('lesson_documents')->delete($document->path);
    $this->actingAs(adminUser())->get($url)->assertNotFound();
    auth()->logout();
    $account = Account::factory()->platformAdmin()->create();
    $platform = libraryPdf(['company_id' => null, 'is_shared' => true]);
    $platformUrl = route('platform.lesson-documents.library.open', ['document' => $platform->public_id]);
    $this->withSession(['platform_account_id' => $account->id])->get($platformUrl)->assertOk();
    $account->update(['status' => 'suspended']);
    $this->get($platformUrl)->assertRedirect(route('platform.login'));
});
