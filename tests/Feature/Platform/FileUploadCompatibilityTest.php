<?php

use Andremellow\Tasks\Models\Task;
use Andremellow\Tasks\Models\TaskType;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Facades\GenerateSignedUploadUrlFacade;
use Livewire\Features\SupportFileUploads\S3DoesntSupportMultipleFileUploads;
use Livewire\Livewire;

it('uses the host task media override with single-file S3 selections and an upload queue', function (): void {
    $finder = app('view')->getFinder();
    $templatePath = $finder->find('tasks::components.editor-content');
    $template = file_get_contents($templatePath);
    $packageComponentPath = base_path('vendor/andremellow/laravel-tasks/resources/views/tasks/show.blade.php');
    $component = file_get_contents($packageComponentPath);

    expect($templatePath)
        ->toBe(resource_path('views/vendor/tasks/components/editor-content.blade.php'))
        ->and(realpath(app('livewire.finder')->resolveSingleFileComponentPath('tasks::tasks.show')))
        ->toBe(realpath($packageComponentPath));

    preg_match('/<input\s+type="file"\s+wire:model="uploads"[^>]*>/', $template, $inputMatches);

    expect($inputMatches)->toHaveCount(1)
        ->and($inputMatches[0])->not->toContain(' multiple')
        ->and($component)->toContain('public array $uploads = []')
        ->and($component)->toContain('foreach ($this->uploads as $upload)')
        ->and($template)->toContain('@foreach($uploads as $upload)')
        ->and($template)->toContain('wire:submit="attachTaskMedia"')
        ->and($template)->toContain('wire:target="uploads,attachTaskMedia"')
        ->and($template)->not->toContain('wire:submit="upload"')
        ->and($template)->not->toContain('$wire.call(\'upload\')');
});

it('keeps the people spreadsheet importer as a single-file upload', function (): void {
    $template = file_get_contents(resource_path('views/components/organization/⚡import-people.blade.php'));

    preg_match('/<flux:input\s+type="file"\s+wire:model="spreadsheet"[^>]*\/>/', $template, $inputMatches);

    expect($inputMatches)->toHaveCount(1)
        ->and($inputMatches[0])->not->toContain(' multiple')
        ->and($template)->toContain('public $spreadsheet;')
        ->and($template)->not->toContain('public array $spreadsheet');
});

it('stores task media through the renamed Livewire action', function (): void {
    Storage::fake('local');

    $account = Account::factory()->platformAdmin()->create();
    $user = User::factory()->create([
        'account_id' => $account->id,
        'email' => $account->email,
    ]);
    $type = TaskType::query()->create([
        'name' => 'Improvement',
        'slug' => 'improvement',
    ]);
    $task = Task::query()->create([
        'task_type_id' => $type->id,
        'created_by' => $user->id,
        'title' => 'Verify media upload',
        'priority' => 'medium',
        'status' => 'backlog',
    ]);

    Livewire::actingAs($user)
        ->test('tasks::tasks.show', ['task' => $task, 'embedded' => true])
        ->set('uploads', [UploadedFile::fake()->image('evidence.jpg')])
        ->call('attachTaskMedia')
        ->assertHasNoErrors()
        ->assertSet('uploads', [])
        ->assertSet('mediaUploadOpen', false);

    expect($task->fresh()->getMedia('task-attachments'))
        ->toHaveCount(1)
        ->first()->name->toBe('evidence.jpg');
});

it('renders the people spreadsheet file input without the multiple attribute', function (): void {
    seedAccessCatalog();

    $html = $this->actingAs(userWithPermissions([Permission::PeopleImport]))
        ->get(route('people.import'))
        ->assertOk()
        ->getContent();

    preg_match('/<input\b(?=[^>]*\btype="file")(?=[^>]*\bwire:model="spreadsheet")[^>]*>/', $html, $inputMatches);

    expect($inputMatches)->toHaveCount(1)
        ->and($inputMatches[0])->not->toMatch('/\bmultiple(?:\s*=|\s|>)/i');
});

it('starts the people spreadsheet upload as a single file on S3', function (): void {
    config()->set('livewire.temporary_file_upload.disk', 's3');

    GenerateSignedUploadUrlFacade::shouldReceive('forS3')
        ->once()
        ->andReturn([
            'path' => 'signed-path',
            'url' => 'https://uploads.example.test/signed-path',
            'headers' => ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ]);

    Livewire::test('organization.import-people')
        ->call('_startUpload', 'spreadsheet', [[
            'name' => 'people.xlsx',
            'size' => 1024,
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]], false)
        ->assertDispatched('upload:generatedSignedUrlForS3');
});

it('proves the S3 upload guard rejects a multiple-file handshake', function (): void {
    config()->set('livewire.temporary_file_upload.disk', 's3');

    expect(fn () => Livewire::test('organization.import-people')
        ->call('_startUpload', 'spreadsheet', [[
            'name' => 'people.xlsx',
            'size' => 1024,
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]], true))
        ->toThrow(S3DoesntSupportMultipleFileUploads::class);
});
