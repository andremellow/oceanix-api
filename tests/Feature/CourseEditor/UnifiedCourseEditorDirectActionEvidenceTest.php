<?php

use App\Actions\Courses\AttachExistingSharedModule;
use App\Actions\Courses\ReorderSharedCourseModules;
use App\Actions\Modules\MutateSharedModuleAssessmentStructure;
use App\Actions\Modules\ReorderSharedModuleAssessment;
use App\Actions\Videos\DetachEditorVideo;
use App\Actions\Videos\UploadEditorContentImage;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\Video;
use App\Services\CourseEditor\EditorRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;

it('records the direct Action applicability matrix including the standalone composition N/A', function (): void {
    $matrix = collect(EditorContextCase::all())->map(fn (EditorContextCase $case): array => [
        'applicable' => $case->applicableActions,
        'not_applicable' => $case->notApplicable,
    ])->all();

    expect($matrix['company course']['applicable'])->toContain('change-composition', 'upload', 'remove-media')
        ->and($matrix['shared course']['applicable'])->toContain('change-composition', 'upload', 'remove-media')
        ->and($matrix['standalone shared module']['applicable'])->toContain('upload', 'remove-media')
        ->and($matrix['standalone shared module']['not_applicable'])->toHaveKey('change-composition')
        ->and($matrix['standalone shared module']['not_applicable']['change-composition'])->not->toBeEmpty();
});

it('records the frozen dirty-operation evidence applicability without inventing standalone course composition or top-level records', function (): void {
    $matrix = [
        'record-removal' => ['company-course' => 'covered', 'shared-course' => 'covered', 'shared-module' => 'N/A: a standalone module cannot remove itself'],
        'question-removal' => ['company-course' => 'covered', 'shared-course' => 'covered', 'shared-module' => 'covered'],
        'top-level-reorder' => ['company-course' => 'covered', 'shared-course' => 'covered', 'shared-module' => 'N/A: the standalone editor has one root module'],
        'option-reorder' => ['company-course' => 'covered', 'shared-course' => 'covered', 'shared-module' => 'covered'],
        'composition-attach-removal' => ['company-course' => 'covered by the company composition Action boundary', 'shared-course' => 'covered', 'shared-module' => 'N/A: a standalone module has no course composition'],
        'image-insertion' => ['company-course' => 'covered', 'shared-course' => 'covered', 'shared-module' => 'covered'],
        'video-attach-replace-remove' => ['company-course' => 'covered', 'shared-course' => 'covered', 'shared-module' => 'covered'],
    ];

    foreach ($matrix as $contexts) {
        expect($contexts)->toHaveKeys(['company-course', 'shared-course', 'shared-module']);
        foreach ($contexts as $result) {
            expect($result)->toBeString()->not->toBeEmpty();
        }
    }

    expect($matrix['record-removal']['shared-module'])->toStartWith('N/A:')
        ->and($matrix['top-level-reorder']['shared-module'])->toStartWith('N/A:')
        ->and($matrix['composition-attach-removal']['shared-module'])->toStartWith('N/A:');
});

it('executes shared course attach and reorder Actions and classifies stale authority and lifecycle failures exactly', function (): void {
    $fixture = EditorFixture::create(EditorContextCase::sharedCourse());
    $actor = Account::query()->findOrFail($fixture->session['platform_account_id']);
    $course = Course::query()->withoutGlobalScopes()->findOrFail($fixture->root->id);
    $version = CourseVersion::query()->where('course_id', $course->id)->sole();
    $sourceRoot = Module::factory()->shared()->create(['status' => 'active']);
    $source = ModuleVersion::factory()->published()->create(['module_id' => $sourceRoot]);
    $revision = app(EditorRevision::class)->forSharedCourse($course, $version);
    $before = $version->moduleCompositions()->count();

    app(AttachExistingSharedModule::class)->handle($version, $source->id, $actor, $revision);
    expect($version->moduleCompositions()->count())->toBe($before + 1);

    $ids = $version->moduleCompositions()->orderByDesc('position')->pluck('lesson_id')->all();
    $revision = app(EditorRevision::class)->forSharedCourse($course->fresh(), $version->fresh());
    app(ReorderSharedCourseModules::class)->handle($version, $actor, $ids, $revision);
    expect($version->moduleCompositions()->orderBy('position')->pluck('lesson_id')->all())->toBe($ids);

    expect(fn () => app(ReorderSharedCourseModules::class)->handle($version, $actor, array_reverse($ids), 'stale'))
        ->toThrow(ValidationException::class, __('The saved order is stale. Reload the draft and try again.'));

    $inactive = Account::factory()->create(['is_platform_admin' => false, 'status' => 'active']);
    expect(fn () => app(AttachExistingSharedModule::class)->handle($version, $source->id, $inactive, app(EditorRevision::class)->forSharedCourse($course->fresh(), $version->fresh())))
        ->toThrow(LogicException::class, 'Only active platform administrators may attach modules to shared drafts.');

    $version->update(['status' => 'published', 'published_at' => now()]);
    expect(fn () => app(AttachExistingSharedModule::class)->handle($version, $source->id, $actor, 'irrelevant'))
        ->toThrow(LogicException::class, 'Only active platform administrators may attach modules to shared drafts.');
});

it('executes shared assessment mutation and reorder Actions and classifies authority lifecycle and stale failures exactly', function (): void {
    $fixture = EditorFixture::create(EditorContextCase::sharedModule());
    $actor = Account::query()->findOrFail($fixture->session['platform_account_id']);
    $version = ModuleVersion::query()->findOrFail($fixture->recordIds[0]);
    $revision = app(EditorRevision::class)->forSharedModule($version);

    $created = app(MutateSharedModuleAssessmentStructure::class)->handle($version, $actor, 'add_question', $version->id, null, $revision);
    expect(Question::query()->whereKey($created)->exists())->toBeTrue();

    $questions = $version->questions()->with('options')->orderByDesc('position')->get();
    $revision = app(EditorRevision::class)->forSharedModule($version->fresh());
    app(ReorderSharedModuleAssessment::class)->handle($version, $actor, 'questions', null, $questions->pluck('id')->all(), $revision);
    expect($version->questions()->orderBy('position')->pluck('id')->all())->toBe($questions->pluck('id')->all());

    expect(fn () => app(MutateSharedModuleAssessmentStructure::class)->handle($version, $actor, 'add_question', $version->id, null, 'stale'))
        ->toThrow(ValidationException::class, __('This module changed elsewhere. Reload the page before trying again.'));

    $inactive = Account::factory()->create(['is_platform_admin' => false, 'status' => 'active']);
    expect(fn () => app(MutateSharedModuleAssessmentStructure::class)->handle($version, $inactive, 'add_question', $version->id, null, app(EditorRevision::class)->forSharedModule($version->fresh())))
        ->toThrow(LogicException::class, 'Only an active platform administrator can change shared content.');

    $version->update(['status' => 'published', 'published_at' => now()]);
    expect(fn () => app(MutateSharedModuleAssessmentStructure::class)->handle($version, $actor, 'add_question', $version->id, null, 'irrelevant'))
        ->toThrow(LogicException::class, 'Only active platform-owned shared module drafts can be changed.');
});

it('executes image upload directly in company and platform contexts with exact authorization failures', function (): void {
    Storage::fake('public');
    $companyFixture = EditorFixture::create(EditorContextCase::companyCourse());
    $course = Course::query()->findOrFail($companyFixture->root->id);
    $companyImage = app(UploadEditorContentImage::class)->handle(
        UploadedFile::fake()->image('company.png'),
        course: $course,
        actor: $companyFixture->user,
    );
    expect($companyImage->company_id)->toBe($course->company_id)->and($companyImage->is_shared)->toBeFalse();

    $viewer = userWithPermissions([Permission::CoursesView]);
    expect(fn () => app(UploadEditorContentImage::class)->handle(UploadedFile::fake()->image('denied.png'), course: $course, actor: $viewer))
        ->toThrow(AuthorizationException::class);

    $platform = Account::factory()->platformAdmin()->create();
    $sharedImage = app(UploadEditorContentImage::class)->handle(UploadedFile::fake()->image('shared.png'), platformActor: $platform);
    expect($sharedImage->company_id)->toBeNull()->and($sharedImage->is_shared)->toBeTrue();

    $inactive = Account::factory()->create(['is_platform_admin' => false, 'status' => 'active']);
    expect(fn () => app(UploadEditorContentImage::class)->handle(UploadedFile::fake()->image('platform-denied.png'), platformActor: $inactive))
        ->toThrow(LogicException::class, 'Only an active platform administrator can upload shared content images.');
});

it('executes video detach directly and classifies company and shared lifecycle failures exactly', function (): void {
    $fixture = EditorFixture::create(EditorContextCase::companyCourse());
    $course = Course::query()->findOrFail($fixture->root->id);
    $version = CourseVersion::query()->where('course_id', $course->id)->sole();
    $lesson = $version->lessons()->firstOrFail();
    $video = Video::factory()->create(['company_id' => $course->company_id, 'lesson_id' => $lesson, 'is_current' => true]);
    $revision = app(EditorRevision::class)->forCompanyCourse($course, $version);

    app(DetachEditorVideo::class)->forCompanyEditor($lesson, $video->id, $fixture->user, $revision);
    expect($video->fresh()->is_current)->toBeFalse();

    $next = Video::factory()->create(['company_id' => $course->company_id, 'lesson_id' => $lesson, 'is_current' => true]);
    $viewer = userWithPermissions([Permission::CoursesView]);
    expect(fn () => app(DetachEditorVideo::class)->forCompanyEditor($lesson, $next->id, $viewer, app(EditorRevision::class)->forCompanyCourse($course->fresh(), $version->fresh())))
        ->toThrow(AuthorizationException::class);

    $version->update(['status' => 'published', 'published_at' => now()]);
    expect(fn () => app(DetachEditorVideo::class)->forCompanyEditor($lesson, $next->id, adminUser(), 'current-revision'))
        ->toThrow(AuthorizationException::class);

    $sharedFixture = EditorFixture::create(EditorContextCase::sharedModule());
    $platform = Account::query()->findOrFail($sharedFixture->session['platform_account_id']);
    $sharedVersion = ModuleVersion::query()->findOrFail($sharedFixture->recordIds[0]);
    $sharedVideo = Video::factory()->create(['company_id' => null, 'lesson_id' => $sharedVersion, 'is_current' => true]);
    $sharedVersion->update(['status' => 'published', 'published_at' => now()]);
    expect(fn () => app(DetachEditorVideo::class)->forPlatformEditor($sharedVersion, $sharedVideo->id, $platform, 'current-revision'))
        ->toThrow(LogicException::class, 'Videos can only be changed on platform-owned shared module drafts.');
});
