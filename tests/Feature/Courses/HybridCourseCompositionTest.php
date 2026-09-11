<?php

use App\Actions\Courses\AddDirectCourseLesson;
use App\Actions\Courses\PublishCourseVersion;
use App\Actions\Courses\UpdateCourseModuleComposition;
use App\Enums\CourseVersionStatus;
use App\Enums\ModuleStatus;
use App\Exceptions\CoursePublicationException;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\UserTrainingAssignment;
use App\Services\Courses\CourseVersionComposition;
use App\Services\Courses\CourseVersionValidator;
use App\Services\Courses\PublicPreviewResolver;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function activeModuleVersion(array $moduleAttributes): ModuleVersion
{
    $module = Module::factory()->create(['status' => ModuleStatus::Active, ...$moduleAttributes]);
    $version = ModuleVersion::factory()->published()->create(['module_id' => $module->id]);
    $module->update(['current_published_version_id' => $version->id]);

    return $version;
}

it('orders company and shared module snapshots in a company course draft', function (): void {
    $company = currentCompany();
    $own = activeModuleVersion(['company_id' => $company->id, 'is_shared' => false]);
    $shared = activeModuleVersion(['company_id' => null, 'is_shared' => true]);
    $course = Course::factory()->draft()->create();
    $draft = CourseVersion::factory()->create(['course_id' => $course->id]);
    $actor = adminUser();

    app(UpdateCourseModuleComposition::class)->handle($draft, [$shared->id, $own->id], $actor);

    expect($draft->moduleCompositions()->pluck('lesson_id')->all())->toBe([$shared->id, $own->id]);
});

it('rejects cross-tenant module injection and published-course mutation', function (): void {
    $course = Course::factory()->draft()->create();
    $draft = CourseVersion::factory()->create(['course_id' => $course->id]);
    $other = Company::factory()->create();
    app(TenantContext::class)->set($other);
    $foreign = activeModuleVersion(['company_id' => $other->id, 'is_shared' => false]);
    app(TenantContext::class)->set($course->company);
    $actor = adminUser();

    expect(fn () => app(UpdateCourseModuleComposition::class)->handle($draft, [$foreign->id], $actor))
        ->toThrow(LogicException::class);

    $existing = activeModuleVersion(['company_id' => $course->company_id, 'is_shared' => false]);
    app(UpdateCourseModuleComposition::class)->handle($draft, [$existing->id], $actor);
    $pivotId = $draft->moduleCompositions()->sole()->id;
    $pivotBefore = (array) DB::table('course_version_lessons')->whereKey($pivotId)->first();
    $draft->update(['status' => CourseVersionStatus::Published]);
    expect(fn () => app(UpdateCourseModuleComposition::class)->handle($draft->fresh(), [], $actor))
        ->toThrow(LogicException::class)
        ->and((array) DB::table('course_version_lessons')->whereKey($pivotId)->first())->toBe($pivotBefore);
});

it('classifies direct mirror rows by provenance and exposes the canonical direct inventory', function (): void {
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $lesson = Lesson::factory()->create(['course_version_id' => $version, 'title' => 'Direct safety lesson']);

    $state = app(CourseVersionComposition::class)->inspect($version);

    expect($state['mode'])->toBe(CourseVersionComposition::DirectLessons)
        ->and($state['directLessons']->pluck('id')->all())->toBe([$lesson->id])
        ->and($state['mirroredDirectRows'])->toHaveCount(1)
        ->and($state['reusableRows'])->toBeEmpty()
        ->and(app(CourseVersionComposition::class)->canonicalLessons($version)->pluck('id')->all())->toBe([$lesson->id]);

    $preview = app(PublicPreviewResolver::class)->items($version)->sole();
    expect($preview['kind'])->toBe('composition')
        ->and($preview['id'])->toBe($state['mirroredDirectRows']->sole()->id)
        ->and($preview['lesson']->id)->toBe($lesson->id);
});

it('preserves both inventories and blocks publication for a pre-existing mixed draft', function (): void {
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $direct = Lesson::factory()->create(['course_version_id' => $version, 'position' => 1]);
    $module = activeModuleVersion(['company_id' => $course->company_id, 'is_shared' => false]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $module->id, 'position' => 2, 'is_required' => true]);

    $before = DB::table('course_version_lessons')->where('course_version_id', $version->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $state = app(CourseVersionComposition::class)->inspect($version);

    expect($state['mode'])->toBe(CourseVersionComposition::Mixed)
        ->and($state['directLessons']->pluck('id')->all())->toBe([$direct->id])
        ->and($state['reusableRows']->pluck('lesson_id')->all())->toBe([$module->id])
        ->and(app(CourseVersionValidator::class)->problems($version))->toContain(__('ui.mixed_composition_error'))
        ->and(DB::table('course_version_lessons')->where('course_version_id', $version->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
});

it('rejects adding a reusable module to direct content without changing questions or answers', function (): void {
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $lesson = Lesson::factory()->create(['course_version_id' => $version]);
    $question = Question::factory()->create(['lesson_id' => $lesson]);
    $option = QuestionOption::factory()->correct()->create(['question_id' => $question]);
    $module = activeModuleVersion(['company_id' => $course->company_id, 'is_shared' => false]);

    expect(fn () => app(UpdateCourseModuleComposition::class)->handle($version, [$module->id], adminUser()))
        ->toThrow(ValidationException::class)
        ->and($lesson->fresh())->not->toBeNull()
        ->and($question->fresh())->not->toBeNull()
        ->and($option->fresh())->not->toBeNull()
        ->and($version->moduleCompositions()->pluck('lesson_id')->all())->toBe([$lesson->id]);
});

it('fails preview closed instead of selecting one side of a mixed draft', function (): void {
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    Lesson::factory()->create(['course_version_id' => $version, 'position' => 1]);
    $module = activeModuleVersion(['company_id' => $course->company_id, 'is_shared' => false]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $module->id, 'position' => 2, 'is_required' => true]);

    expect(fn () => app(PublicPreviewResolver::class)->items($version))
        ->toThrow(HttpException::class, __('ui.mixed_composition_error'));
});

it('rejects a direct lesson in module mode without changing the composition', function (): void {
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $module = activeModuleVersion(['company_id' => $course->company_id, 'is_shared' => false]);
    app(UpdateCourseModuleComposition::class)->handle($version, [$module->id], adminUser());
    $before = $version->moduleCompositions()->pluck('lesson_id')->all();

    expect(fn () => app(AddDirectCourseLesson::class)->handle($version, adminUser()))
        ->toThrow(ValidationException::class)
        ->and($version->lessons()->count())->toBe(0)
        ->and($version->moduleCompositions()->pluck('lesson_id')->all())->toBe($before);
});

it('removes reusable modules from a mixed draft without colliding with preserved direct mirrors', function (): void {
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $direct = Lesson::factory()->create(['course_version_id' => $version, 'position' => 1, 'title' => 'Direct original']);
    $question = Question::factory()->create(['lesson_id' => $direct, 'prompt' => 'Preserved question']);
    $option = QuestionOption::factory()->correct()->create(['question_id' => $question, 'text' => 'Preserved answer']);
    $firstModule = activeModuleVersion(['company_id' => $course->company_id, 'is_shared' => false]);
    $secondModule = activeModuleVersion(['company_id' => $course->company_id, 'is_shared' => false]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $firstModule->id, 'position' => 2, 'is_required' => true]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $secondModule->id, 'position' => 3, 'is_required' => true]);
    $directBefore = (array) DB::table('lessons')->whereKey($direct->id)->first();
    $questionBefore = (array) DB::table('questions')->whereKey($question->id)->first();
    $optionBefore = (array) DB::table('question_options')->whereKey($option->id)->first();
    $actor = adminUser();

    app(UpdateCourseModuleComposition::class)->handle($version, [$secondModule->id], $actor);

    expect($version->moduleCompositions()->orderBy('position')->pluck('lesson_id')->all())->toBe([$direct->id, $secondModule->id])
        ->and($version->moduleCompositions()->where('lesson_id', $secondModule->id)->value('position'))->toBe(2);

    app(UpdateCourseModuleComposition::class)->handle($version, [], $actor);

    expect(app(CourseVersionComposition::class)->inspect($version)['mode'])->toBe(CourseVersionComposition::DirectLessons)
        ->and($version->moduleCompositions()->pluck('lesson_id')->all())->toBe([$direct->id])
        ->and((array) DB::table('lessons')->whereKey($direct->id)->first())->toBe($directBefore)
        ->and((array) DB::table('questions')->whereKey($question->id)->first())->toBe($questionBefore)
        ->and((array) DB::table('question_options')->whereKey($option->id)->first())->toBe($optionBefore);
});

it('fails publication closed when any direct lesson mirror is missing while preview remains complete', function (): void {
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $first = Lesson::factory()->create(['course_version_id' => $version, 'position' => 1]);
    $second = Lesson::factory()->create(['course_version_id' => $version, 'position' => 2]);
    $version->moduleCompositions()->where('lesson_id', $second->id)->delete();

    expect(app(PublicPreviewResolver::class)->items($version)->pluck('lesson.id')->all())->toBe([$first->id, $second->id])
        ->and(app(CourseVersionValidator::class)->problems($version))->toBe([__('ui.incomplete_direct_composition_error')]);

    try {
        app(PublishCourseVersion::class)->handle($version, adminUser()->id);
        test()->fail('Publication accepted an incomplete direct mirror.');
    } catch (CoursePublicationException $exception) {
        expect($exception->problems)->toBe([__('ui.incomplete_direct_composition_error')]);
    }

    expect($version->fresh()->status)->toBe(CourseVersionStatus::Draft);
});

it('preserves module assessment content through preview and published employee training', function (): void {
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $module = activeModuleVersion(['company_id' => $course->company_id, 'is_shared' => false]);
    $module->updateQuietly(['content_markdown' => '<p>Module content</p>']);
    $question = Question::factory()->create(['lesson_id' => $module, 'prompt' => 'Module assessment prompt']);
    QuestionOption::factory()->correct()->create(['question_id' => $question, 'position' => 1, 'text' => 'Correct module answer']);
    QuestionOption::factory()->create(['question_id' => $question, 'position' => 2, 'text' => 'Other module answer']);
    app(UpdateCourseModuleComposition::class)->handle($version, [$module->id], adminUser());

    $previewLesson = app(PublicPreviewResolver::class)->items($version)->sole()['lesson'];
    expect($previewLesson->questions->sole()->prompt)->toBe('Module assessment prompt')
        ->and($previewLesson->questions->sole()->options->pluck('text')->all())->toBe(['Correct module answer', 'Other module answer'])
        ->and(app(CourseVersionValidator::class)->problems($version))->toBe([]);

    app(PublishCourseVersion::class)->handle($version, adminUser()->id);
    $employee = employeeUser();
    $assignment = UserTrainingAssignment::factory()->create([
        'user_id' => $employee->id,
        'course_id' => $course->id,
        'course_version_id' => $version->id,
    ]);

    expect($assignment->includesLesson($module))->toBeTrue();
    $this->actingAs($employee)
        ->get(route('my-training.lesson', ['assignment' => $assignment, 'lesson' => $module]))
        ->assertOk()
        ->assertSee('Module assessment prompt')
        ->assertSee('Correct module answer')
        ->assertSee('Other module answer');
});
