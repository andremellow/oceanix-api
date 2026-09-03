<?php

use App\Actions\Modules\CreateAndAttachSharedModule;
use App\Models\Account;
use App\Models\ContentImage;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('protects every shared course administration route with platform access', function (): void {
    $course = Course::factory()->shared()->create();

    $routes = [
        route('platform.shared-courses.index'),
        route('platform.shared-courses.show', ['course' => $course]),
        route('platform.shared-courses.editor', ['course' => $course]),
    ];

    foreach ($routes as $route) {
        $this->actingAs(adminUser())->get($route)->assertForbidden();
    }
});

it('lets a platform administrator open the shared course directory and navigation', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    Course::factory()->shared()->create(['title' => 'Global Offshore Safety']);

    $this->withSession(['platform_account_id' => $account->id])
        ->get(route('platform.shared-courses.index'))
        ->assertOk()
        ->assertSee('Global Offshore Safety')
        ->assertSee(__('Shared courses'))
        ->assertSee(route('platform.shared-courses.index'), escape: false)
        ->assertSee(__('Shared modules'))
        ->assertSee(route('platform.shared-modules.index'), escape: false);
});

it('protects and exposes the shared module directory route', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    Module::factory()->shared()->create(['status' => 'published', 'title' => 'Published raw status module']);

    $this->get(route('platform.shared-modules.index'))
        ->assertRedirect(route('platform.login'));

    $this->actingAs(adminUser())->get(route('platform.shared-modules.index'))->assertForbidden();

    $this->withSession(['platform_account_id' => $account->id])
        ->get(route('platform.shared-modules.index'))
        ->assertOk()
        ->assertSee(__('New Shared Module'))
        ->assertSee('Published raw status module')
        ->assertSee(__('Published'));
});

it('opens shared module detail with a raw retired aggregate status', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $module = Module::factory()->shared()->create(['status' => 'retired', 'title' => 'Retired shared module']);

    $this->withSession(['platform_account_id' => $account->id])
        ->get(route('platform.shared-modules.show', ['module' => $module]))
        ->assertOk()
        ->assertSee('Retired shared module')
        ->assertSee(__('Retired'));
});

it('rejects company-owned courses from platform shared detail and editor routes', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->create();

    $this->withSession(['platform_account_id' => $account->id])
        ->get(route('platform.shared-courses.show', ['course' => $course]))
        ->assertNotFound();

    $this->withSession(['platform_account_id' => $account->id])
        ->get(route('platform.shared-courses.editor', ['course' => $course]))
        ->assertNotFound();
});

it('renders the shared course hero description at full width', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->draft()->create([
        'description' => 'A detailed shared course description.',
    ]);

    $this->withSession(['platform_account_id' => $account->id])
        ->get(route('platform.shared-courses.show', ['course' => $course]))
        ->assertOk()
        ->assertSee('A detailed shared course description.')
        ->assertSee('max-w-none', false);
});

it('creates shared courses with platform ownership from the directory', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.shared-courses.index')
        ->set('code', 'GLOBAL-101')
        ->set('title', 'Global Safety')
        ->set('description', 'Reusable offshore safety training.')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect();

    $course = Course::query()->where('code', 'GLOBAL-101')->firstOrFail();
    expect($course->is_shared)->toBeTrue()
        ->and($course->company_id)->toBeNull()
        ->and($course->draftVersion())->not->toBeNull();
});

it('opens the complete course and module editor on one continuous screen', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->draft()->create();
    $courseVersion = CourseVersion::factory()->create(['course_id' => $course]);
    $module = Module::factory()->shared()->create(['status' => 'published', 'published_at' => now()]);
    $published = ModuleVersion::query()->findOrFail($module->id);
    Video::factory()->create(['lesson_id' => $published->id, 'company_id' => null, 'status' => 'ready']);
    CourseVersionModule::query()->create([
        'course_version_id' => $courseVersion->id,
        'lesson_id' => $published->id,
        'position' => 1,
        'is_required' => true,
    ]);
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->assertSee(__('Course details'))
        ->assertSee(__('Module title'))
        ->assertSee(__('Search shared modules'))
        ->assertSee(__('Shared'))
        ->assertSee(__('Managed by platform'))
        ->assertSeeHtml('aria-expanded="true"')
        ->assertSeeHtml('aria-controls="shared-course-module-panel-')
        ->assertDontSee(__('Replace video'))
        ->assertDontSee(__('Watch threshold (%)'))
        ->assertSee(__('Assessment'))
        ->set('courseForm.title', 'Updated shared course')
        ->set('modules.0.title', 'Updated shared module')
        ->set('versionForm.description', 'Employee wording')
        ->set('courseForm.description', 'Catalog wording')
        ->set('editorDirty', true)
        ->call('saveDraft', false)
        ->assertHasNoErrors();

    $draft = ModuleVersion::query()->where('lineage_uuid', $published->lineage_uuid)->where('status', 'draft')->firstOrFail();
    expect($draft->video)->not->toBeNull();
    expect($course->fresh()->title)->toBe('Updated shared course')
        ->and($course->fresh()->description)->toBe('Catalog wording')
        ->and($courseVersion->fresh()->description)->toBe('Employee wording')
        ->and($draft->fresh()->title)->toBe('Updated shared module');
});

it('creates a shared module in the course editor and attaches it last', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->draft()->create();
    $courseVersion = CourseVersion::factory()->create(['course_id' => $course]);
    $existing = Module::factory()->shared()->create(['status' => 'draft']);
    $moduleCountBefore = Module::query()->count();
    CourseVersionModule::query()->create([
        'course_version_id' => $courseVersion->id,
        'lesson_id' => $existing->id,
        'position' => 4,
        'is_required' => true,
    ]);
    $this->withSession(['platform_account_id' => $account->id]);

    $editor = Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->assertSee(__('Create new shared module'))
        ->assertDontSeeHtml('aria-label="'.__('New shared module').'"')
        ->assertSeeInOrder([__('Add an existing shared module'), __('Create new shared module'), __('Publish')])
        ->call('openNewModuleModal')
        ->assertSet('newModuleModalOpen', true)
        ->set('newModuleForm.code', ' new-101 ')
        ->set('newModuleForm.title', 'New safety module')
        ->set('newModuleForm.description', 'Reusable content')
        ->call('createNewModule')
        ->assertHasNoErrors()
        ->assertSet('newModuleModalOpen', false)
        ->assertDispatched('shared-module-created');

    $created = Module::query()->where('code', 'NEW-101')->sole();
    $composition = CourseVersionModule::query()->where('lesson_id', $created->id)->sole();

    expect($created->is_shared)->toBeTrue()
        ->and($created->company_id)->toBeNull()
        ->and($created->getRawOriginal('status'))->toBe('draft')
        ->and($created->version_number)->toBe(1)
        ->and($created->published_by_account_id)->toBe($account->id)
        ->and($created->title)->toBe('New safety module')
        ->and($created->description)->toBe('Reusable content')
        ->and(Module::query()->count())->toBe($moduleCountBefore + 1)
        ->and($composition->course_version_id)->toBe($courseVersion->id)
        ->and($composition->position)->toBe(5)
        ->and(CourseVersionModule::query()->where('lesson_id', $existing->id)->exists())->toBeTrue();

    $editor->assertSet('expanded', fn (array $expanded): bool => in_array($created->id, $expanded, true));
});

it('keeps the new module form open and retryable after an unexpected failure', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $this->withSession(['platform_account_id' => $account->id]);

    $component = Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->call('openNewModuleModal')
        ->set('newModuleForm.code', 'RETRY-101')
        ->set('newModuleForm.title', 'Retry module')
        ->set('newModuleForm.description', 'Keep this description');

    $version->update(['status' => 'published']);

    $component->call('createNewModule')
        ->assertSet('newModuleModalOpen', true)
        ->assertSet('newModuleForm.code', 'RETRY-101')
        ->assertSet('newModuleForm.title', 'Retry module')
        ->assertSet('newModuleForm.description', 'Keep this description')
        ->assertSet('newModuleError', __('The shared module could not be created. Check the course draft and try again.'));

    expect(Module::query()->where('code', 'RETRY-101')->exists())->toBeFalse();

    $version->update(['status' => 'draft']);

    $component->call('createNewModule')
        ->assertHasNoErrors()
        ->assertSet('newModuleModalOpen', false)
        ->assertSet('newModuleError', null)
        ->assertDispatched('shared-module-created');
});

it('does not convert revoked platform authorization into a retryable form error', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->draft()->create();
    CourseVersion::factory()->create(['course_id' => $course]);
    $this->withSession(['platform_account_id' => $account->id]);

    $component = Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->call('openNewModuleModal')
        ->set('newModuleForm.code', 'DENIED-101')
        ->set('newModuleForm.title', 'Denied module');

    $account->update(['is_platform_admin' => false]);

    $component->call('createNewModule')->assertForbidden();

    expect(Module::query()->where('code', 'DENIED-101')->exists())->toBeFalse()
        ->and(CourseVersionModule::query()->exists())->toBeFalse();
});

it('shows a friendly duplicate shared module code error without writing', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->draft()->create();
    CourseVersion::factory()->create(['course_id' => $course]);
    Module::factory()->shared()->create(['code' => 'DUP-101', 'status' => 'draft']);
    $before = Module::query()->count();
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->call('openNewModuleModal')
        ->set('newModuleForm.code', ' dup-101 ')
        ->set('newModuleForm.title', 'Duplicate')
        ->call('createNewModule')
        ->assertHasErrors(['newModuleForm.code'])
        ->assertSet('newModuleModalOpen', true)
        ->assertSet('newModuleForm.code', ' dup-101 ')
        ->assertSet('newModuleForm.title', 'Duplicate');

    expect(Module::query()->count())->toBe($before)
        ->and(CourseVersionModule::query()->count())->toBe(0);
});

it('atomically rejects revoked administrators and ineligible course versions', function (): void {
    $action = app(CreateAndAttachSharedModule::class);
    $account = Account::factory()->platformAdmin()->create();
    $sharedCourse = Course::factory()->shared()->draft()->create();
    $draft = CourseVersion::factory()->create(['course_id' => $sharedCourse]);
    $account->update(['is_platform_admin' => false]);

    expect(fn () => $action->handle($draft, $account, 'REVOKED', 'Revoked'))
        ->toThrow(LogicException::class);

    $activeAdmin = Account::factory()->platformAdmin()->create();
    $published = CourseVersion::factory()->published()->create(['course_id' => $sharedCourse, 'version_number' => 2]);
    $companyCourse = Course::factory()->draft()->create();
    $companyDraft = CourseVersion::factory()->create(['course_id' => $companyCourse]);

    expect(fn () => $action->handle($published, $activeAdmin, 'PUBLISHED', 'Published'))
        ->toThrow(LogicException::class)
        ->and(fn () => $action->handle($companyDraft, $activeAdmin, 'COMPANY', 'Company'))
        ->toThrow(LogicException::class)
        ->and(Module::query()->whereIn('code', ['REVOKED', 'PUBLISHED', 'COMPANY'])->exists())->toBeFalse()
        ->and(CourseVersionModule::query()->whereIn('course_version_id', [$published->id, $companyDraft->id])->exists())->toBeFalse();
});

it('validates the new shared module form without writing', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->draft()->create();
    CourseVersion::factory()->create(['course_id' => $course]);
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->call('openNewModuleModal')
        ->set('newModuleForm.code', '')
        ->set('newModuleForm.title', '')
        ->set('newModuleForm.description', str_repeat('x', 5001))
        ->call('createNewModule')
        ->assertHasErrors([
            'newModuleForm.code' => 'required',
            'newModuleForm.title' => 'required',
            'newModuleForm.description' => 'max',
        ])
        ->assertSet('newModuleModalOpen', true);

    expect(Module::query()->exists())->toBeFalse()
        ->and(CourseVersionModule::query()->exists())->toBeFalse();
});

it('validates shared module fields before writing them', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->draft()->create();
    $courseVersion = CourseVersion::factory()->create(['course_id' => $course]);
    $module = Module::factory()->shared()->create(['status' => 'published', 'published_at' => now()]);
    CourseVersionModule::query()->create(['course_version_id' => $courseVersion->id, 'lesson_id' => $module->id, 'position' => 1, 'is_required' => true]);
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->set('modules.0.minimum_watch_percentage', 0)
        ->set('editorDirty', true)
        ->call('saveDraft', false)
        ->assertSet('saveError', fn (?string $error): bool => filled($error));

    $draft = ModuleVersion::query()->where('lineage_uuid', $module->lineage_uuid)->where('status', 'draft')->firstOrFail();
    expect($draft->minimum_watch_percentage)->not->toBe(0);
});

it('does not partially publish modules when a later module is invalid', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->draft()->create();
    $courseVersion = CourseVersion::factory()->create(['course_id' => $course]);
    $first = Module::factory()->shared()->create(['status' => 'published', 'published_at' => now()]);
    $second = Module::factory()->shared()->create(['status' => 'published', 'published_at' => now()]);
    Video::factory()->create(['lesson_id' => $first->id, 'company_id' => null, 'status' => 'ready']);
    $question = Question::factory()->create(['lesson_id' => $first->id, 'company_id' => null]);
    QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'company_id' => null, 'position' => 1]);
    QuestionOption::factory()->create(['question_id' => $question->id, 'company_id' => null, 'position' => 2]);
    foreach ([$first, $second] as $position => $module) {
        CourseVersionModule::query()->create(['course_version_id' => $courseVersion->id, 'lesson_id' => $module->id, 'position' => $position + 1, 'is_required' => true]);
    }
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->call('publish')
        ->assertHasErrors('publish');

    $drafts = ModuleVersion::query()->whereIn('lineage_uuid', [$first->lineage_uuid, $second->lineage_uuid])->where('status', 'draft')->count();
    expect($drafts)->toBe(2)
        ->and($courseVersion->fresh()->status->value)->toBe('draft');
});

it('uploads and reuses images from the shared visual editor library', function (): void {
    Storage::fake('public');
    $account = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->draft()->create();
    $courseVersion = CourseVersion::factory()->create(['course_id' => $course]);
    $module = Module::factory()->shared()->create(['status' => 'published', 'published_at' => now()]);
    CourseVersionModule::query()->create([
        'course_version_id' => $courseVersion->id,
        'lesson_id' => $module->id,
        'position' => 1,
        'is_required' => true,
    ]);
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire\Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->call('openImageLibrary', 'modules.0.content_markdown')
        ->assertSet('imageLibraryOpen', true)
        ->set('contentImageUpload', UploadedFile::fake()->image('procedure.webp', 800, 600))
        ->call('uploadContentImage')
        ->assertHasNoErrors()
        ->assertDispatched('oceanix:insert-image')
        ->assertSet('imageLibraryOpen', false);

    $image = ContentImage::query()->sole();
    expect($image->is_shared)->toBeTrue()
        ->and($image->company_id)->toBeNull()
        ->and($image->disk)->toBe('public')
        ->and($image->url())->toBe(Storage::disk('public')->url($image->path));
    Storage::disk('public')->assertExists($image->path);
});
