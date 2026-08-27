<?php

use App\Models\Account;
use App\Models\ContentImage;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Module;
use App\Models\ModuleVersion;
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
        ->assertSee(route('platform.shared-courses.index'), escape: false);
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
        ->assertSee(__('Replace video'))
        ->assertSee(__('Assessment'))
        ->set('courseForm.title', 'Updated shared course')
        ->set('modules.0.title', 'Updated shared module')
        ->assertHasNoErrors();

    $draft = ModuleVersion::query()->where('lineage_uuid', $published->lineage_uuid)->where('status', 'draft')->firstOrFail();
    expect($draft->video)->not->toBeNull();
    expect($course->fresh()->title)->toBe('Updated shared course')
        ->and($draft->fresh()->title)->toBe('Updated shared module');
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
        ->assertSet('imageLibraryOpen', false);

    $image = ContentImage::query()->sole();
    $draft = ModuleVersion::query()->where('lineage_uuid', $module->lineage_uuid)->where('status', 'draft')->firstOrFail();
    expect($image->is_shared)->toBeTrue()
        ->and($image->company_id)->toBeNull()
        ->and($draft->content_markdown)->toContain($image->url());
    Storage::disk('public')->assertExists($image->path);
});
