<?php

use App\Models\LessonDocument;
use App\Models\ModuleVersion;
use Database\Factories\AccountFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Facades\GenerateSignedUploadUrlFacade;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Oceanix\Platform\Models\Account;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;

beforeEach(function () {
    config(['livewire.temporary_file_upload.disk' => 'local']);
    Storage::fake('tmp-for-tests');
});

function platformTransportEditor(string $context): array
{
    $case = EditorContextCase::all()[$context];
    $fixture = EditorFixture::create($case);
    test()->withSession($fixture->session + ['company_id' => currentCompany()->id]);

    return [$fixture, Livewire::test($case->component, $fixture->routeParameters())];
}

function platformTransportUrl($editor, string $name = 'pdfUpload'): string
{
    $url = null;
    $editor->call('_startUpload', $name, [['name' => 'sample.pdf', 'size' => 100, 'type' => 'application/pdf']], false)
        ->assertDispatched('upload:generatedSignedUrl', function ($event, $parameters) use (&$url) {
            $url = $parameters['url'];

            return true;
        });

    return $url;
}

it('uploads through the real native transport from a shared editor with a disabled tenant', function ($context, $image) {
    $company = currentCompany();
    $company->update(['compliance_access_enabled' => false]);
    [$fixture, $editor] = platformTransportEditor($context);
    $url = platformTransportUrl($editor, $image ? 'contentImageUpload' : 'pdfUpload');
    $file = $image ? UploadedFile::fake()->image('image.png') : UploadedFile::fake()->createWithContent('sample.pdf', "%PDF-1.4\n%%EOF");
    $response = $this->post($url, ['files' => [$file]])->assertOk();
    expect(parse_url($url, PHP_URL_PATH))->toStartWith('/platform/editor-uploads/');
    $path = TemporaryUploadedFile::extractPathFromSignedPath($response->json('paths.0'));
    expect($path)->not->toBeFalse();
    Storage::disk('tmp-for-tests')->assertExists('livewire-tmp/'.$path);
    expect(session('company_id'))->toBe($company->id);
})->with(['shared course', 'standalone shared module'])->with([false, true]);

it('rechecks platform authorization before writing an issued upload', function ($state) {
    [$fixture, $editor] = platformTransportEditor('shared course');
    $url = platformTransportUrl($editor);
    $account = Account::findOrFail($fixture->session['platform_account_id']);
    match ($state) {
        'missing' => session()->forget('platform_account_id'),
        'revoked' => $account->update(['is_platform_admin' => false]),
        'inactive' => $account->update(['status' => 'inactive']),
        'different' => session()->put('platform_account_id', AccountFactory::new()->platformAdmin()->create()->id),
    };
    $response = $this->post($url, ['files' => [UploadedFile::fake()->create('test.pdf', 1, 'application/pdf')]]);
    expect($response->status())->toBeIn([302, 403]);
    expect(Storage::disk('tmp-for-tests')->allFiles())->toBeEmpty();
})->with(['missing', 'revoked', 'inactive', 'different']);

it('rejects an altered or expired platform signature without storing files', function ($kind) {
    [$fixture, $editor] = platformTransportEditor('shared course');
    $url = platformTransportUrl($editor);
    if ($kind === 'expired') {
        $this->travel(10)->minutes();
    } else {
        $url .= '&unexpected=changed';
    }
    $this->post($url, ['files' => [UploadedFile::fake()->create('test.pdf', 1, 'application/pdf')]])->assertUnauthorized();
    expect(Storage::disk('tmp-for-tests')->allFiles())->toBeEmpty();
})->with(['altered', 'expired']);

it('rejects a different signed actor even with a valid signature', function () {
    [$fixture, $editor] = platformTransportEditor('shared course');
    $other = AccountFactory::new()->platformAdmin()->create();
    $url = URL::temporarySignedRoute('platform.editor-uploads.store', now()->addMinutes(5), ['platformAccount' => $other->id]);
    $this->post($url, ['files' => [UploadedFile::fake()->create('test.pdf', 1, 'application/pdf')]])->assertForbidden();
    expect(Storage::disk('tmp-for-tests')->allFiles())->toBeEmpty();
});

it('retains native temporary upload validation', function () {
    [$fixture, $editor] = platformTransportEditor('shared course');
    $url = platformTransportUrl($editor);
    config(['livewire.temporary_file_upload.rules' => ['file', 'max:1']]);
    $this->postJson($url, ['files' => [UploadedFile::fake()->create('too-large.pdf', 2, 'application/pdf')]])->assertUnprocessable();
    expect(Storage::disk('tmp-for-tests')->allFiles())->toBeEmpty();
});

it('retains native temporary upload throttling', function () {
    config(['livewire.temporary_file_upload.middleware' => 'throttle:1,1']);
    [$fixture, $editor] = platformTransportEditor('shared course');
    $url = platformTransportUrl($editor);
    $this->post($url, ['files' => [UploadedFile::fake()->create('test.pdf', 1, 'application/pdf')]])->assertOk();
    $this->post($url, ['files' => [UploadedFile::fake()->create('test.pdf', 1, 'application/pdf')]])->assertStatus(429);
});

it('retains tenant transport and denies disabled tenants even with a platform session', function () {
    $fixture = EditorFixture::create(EditorContextCase::companyCourse());
    $this->actingAs($fixture->user)->withSession(['platform_account_id' => AccountFactory::new()->platformAdmin()->create()->id, 'company_id' => currentCompany()->id]);
    $editor = Livewire::test($fixture->context->component, $fixture->routeParameters());
    $url = platformTransportUrl($editor);
    expect(parse_url($url, PHP_URL_PATH))->toBe(parse_url(route('livewire.upload-file'), PHP_URL_PATH));
    currentCompany()->update(['compliance_access_enabled' => false]);
    $this->post($url, ['files' => [UploadedFile::fake()->create('test.pdf', 1, 'application/pdf')]])->assertForbidden();
    expect(Storage::disk('tmp-for-tests')->allFiles())->toBeEmpty();
    currentCompany()->update(['compliance_access_enabled' => true]);
    $this->post($url, ['files' => [UploadedFile::fake()->create('test.pdf', 1, 'application/pdf')]])->assertOk();
});

it('delegates shared editor S3 handshakes to the native signer', function () {
    [$fixture, $editor] = platformTransportEditor('standalone shared module');
    config(['livewire.temporary_file_upload.disk' => 's3']);
    GenerateSignedUploadUrlFacade::shouldReceive('forS3')->once()->andReturn(['url' => 'https://example.test/signed', 'path' => 'test', 'headers' => []]);
    $editor->call('_startUpload', 'pdfUpload', [['name' => 'test.pdf', 'size' => 100, 'type' => 'application/pdf']], false)
        ->assertDispatched('upload:generatedSignedUrlForS3');
});

it('rechecks final PDF attachment authorization after a completed temporary upload', function ($context, $revocation) {
    Storage::fake('lesson_documents');
    [$fixture, $editor] = platformTransportEditor($context);
    $editor->call('openPdfModal', 'records.0.content_markdown', '', 'transport-final-authorization');
    $url = platformTransportUrl($editor);
    $response = $this->post($url, ['files' => [new UploadedFile(base_path('tests/Fixtures/lesson-guide.pdf'), 'guide.pdf', 'application/pdf', null, true)]])->assertOk();
    $editor->call('_finishUpload', 'pdfUpload', $response->json('paths'), false);
    $record = ModuleVersion::findOrFail($fixture->recordIds[0]);
    $before = $record->getRawOriginal();
    if ($revocation !== null) {
        Account::findOrFail($fixture->session['platform_account_id'])->update($revocation === 'inactive' ? ['status' => 'inactive'] : ['is_platform_admin' => false]);
        $editor->call('uploadPdf')->assertForbidden();
        expect(LessonDocument::count())->toBe(0)
            ->and($record->documents()->count())->toBe(0)
            ->and($record->fresh()->getRawOriginal())->toBe($before)
            ->and(Storage::disk('lesson_documents')->allFiles())->toBeEmpty();
    } else {
        $editor->call('uploadPdf')->assertHasNoErrors()->assertDispatched('oceanix:insert-pdf');
        expect(LessonDocument::count())->toBe(1)->and($record->documents()->count())->toBe(1);
        Storage::disk('lesson_documents')->assertExists(LessonDocument::sole()->path);
    }
})->with(['shared course', 'standalone shared module'])->with([null, 'inactive', 'revoked']);
