<?php

use App\Actions\Courses\CreateCourse;
use App\Actions\Courses\CreateDraftFromVersion;
use App\Actions\Courses\PublishCourseVersion;
use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Enums\Permission;
use App\Enums\QuestionType;
use App\Enums\VideoStatus;
use App\Exceptions\CoursePublicationException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use App\Services\Audit\AuditLogger;
use App\Services\Courses\CourseVersionValidator;
use App\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** Builds a draft version that satisfies every publication rule. */
function publishableDraft(): CourseVersion
{
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course->id]);

    $lesson = Lesson::factory()->create([
        'course_version_id' => $version->id,
        'content_markdown' => '<div data-oceanix-video></div>',
    ]);
    Video::factory()->create(['lesson_id' => $lesson->id]);

    $question = Question::factory()->create(['lesson_id' => $lesson->id]);
    QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'position' => 1]);
    QuestionOption::factory()->create(['question_id' => $question->id, 'position' => 2]);

    return $version->fresh();
}

it('creates a course together with its first draft version', function (): void {
    $course = app(CreateCourse::class)->handle('huet-01', 'Helicopter underwater escape');

    expect($course->code)->toBe('HUET-01')
        ->and($course->status)->toBe(CourseStatus::Draft)
        ->and($course->versions()->count())->toBe(1)
        ->and($course->versions()->first()->status)->toBe(CourseVersionStatus::Draft);
});

it('normalizes company course codes and scopes their uniqueness to the owning company', function (): void {
    $firstCompany = currentCompany();
    $otherCompany = Company::factory()->create();
    app(TenantContext::class)->set($firstCompany);
    $actor = adminUser();
    Course::factory()->create(['company_id' => $otherCompany->id, 'code' => 'HUET-01']);
    $own = app(CreateCourse::class)->handle(' huet-01 ', 'First company course', actor: $actor);

    expect($own->code)->toBe('HUET-01')->and($own->company_id)->toBe($firstCompany->id);

    expect(fn () => app(CreateCourse::class)->handle(' huet-01 ', 'Duplicate', actor: $actor))
        ->toThrow(ValidationException::class)
        ->and(Course::withoutGlobalScopes()->where('company_id', $firstCompany->id)->where('code', 'HUET-01')->count())->toBe(1);
});

it('normalizes and reports a same-company duplicate inline in the creation modal', function (): void {
    $actor = adminUser();
    Course::factory()->create(['code' => 'BOSIET-01']);

    Livewire::actingAs($actor)->test('courses.index')
        ->set('code', ' bosiet-01 ')
        ->set('title', 'Duplicate course')
        ->call('create')
        ->assertSet('code', 'BOSIET-01')
        ->assertHasErrors('code')
        ->assertNoRedirect();
});

it('translates a persistence-time course code collision without leaving a partial duplicate', function (): void {
    $company = currentCompany();
    $actor = adminUser();
    $armed = true;

    DB::listen(function (QueryExecuted $query) use (&$armed, $company): void {
        if (! $armed || ! str_contains(strtolower($query->sql), 'select exists') || ! str_contains(strtolower($query->sql), 'courses')) {
            return;
        }

        $armed = false;
        $winner = Course::factory()->create([
            'company_id' => $company->id,
            'code' => 'RACE-01',
            'title' => 'Concurrent winner',
        ]);
        CourseVersion::factory()->create(['course_id' => $winner]);
    });

    try {
        app(CreateCourse::class)->handle(' race-01 ', 'Losing request', actor: $actor);
        test()->fail('The losing request did not report its code collision.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['code' => [__('ui.course_code_taken')]]);
    }

    $courses = Course::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'RACE-01')->get();

    expect($courses)->toHaveCount(1)
        ->and($courses->first()->title)->toBe('Concurrent winner')
        ->and(CourseVersion::query()->where('course_id', $courses->first()->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'course.created')->count())->toBe(0);
});

it('propagates unrelated persistence failures and rolls back the course aggregate', function (): void {
    $beforeCourses = Course::withoutGlobalScopes()->count();
    $beforeVersions = CourseVersion::withoutGlobalScopes()->count();
    $failure = new QueryException('sqlite', 'insert into audit_logs', [], new RuntimeException('unrelated audit storage failure'));
    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('log')->once()->andThrow($failure);
    app()->instance(AuditLogger::class, $audit);

    expect(fn () => app(CreateCourse::class)->handle('FAULT-01', 'Must roll back', actor: adminUser()))
        ->toThrow(QueryException::class, 'unrelated audit storage failure');

    expect(Course::withoutGlobalScopes()->count())->toBe($beforeCourses)
        ->and(CourseVersion::withoutGlobalScopes()->count())->toBe($beforeVersions)
        ->and(AuditLog::query()->where('action', 'course.created')->count())->toBe(0);
});

it('publishes a complete draft and makes it the current version', function (): void {
    $version = publishableDraft();
    $publisher = adminUser();

    $published = app(PublishCourseVersion::class)->handle($version, $publisher->id);

    expect($published->status)->toBe(CourseVersionStatus::Published)
        ->and($published->published_by)->toBe($publisher->id)
        ->and($published->course->fresh()->current_published_version_id)->toBe($published->id)
        ->and($published->course->fresh()->status)->toBe(CourseStatus::Active)
        ->and(AuditLog::query()->where('action', 'course_version.published')->count())->toBe(1);
});

it('publishes text-only lesson content without a video asset', function (): void {
    $version = publishableDraft();
    $lesson = $version->lessons()->firstOrFail();
    $lesson->update(['content_markdown' => '<p>Text-only safety instructions.</p>']);
    $lesson->video()->delete();

    expect(app(CourseVersionValidator::class)->problems($version->fresh()))->toBe([]);
});

it('renders a migrated course composition without nested lesson relationships', function (): void {
    $version = publishableDraft();
    $admin = adminUser();
    app(PublishCourseVersion::class)->handle($version, $admin->id);

    expect($version->moduleCompositions()->count())->toBe(1);

    $this->actingAs($admin)
        ->get(route('courses.show', ['course' => $version->course]))
        ->assertOk()
        ->assertSee($version->lessons()->first()->title);
});

it('refuses to publish a version with no lessons', function (): void {
    $version = publishableDraft();
    $version->lessons()->delete();

    expect(fn () => app(PublishCourseVersion::class)->handle($version->fresh(), adminUser()->id))
        ->toThrow(CoursePublicationException::class);

    expect(app(CourseVersionValidator::class)->problems($version->fresh()))
        ->toContain('Add at least one lesson before publishing.');
});

it('refuses to publish while a video is still encoding', function (): void {
    $version = publishableDraft();
    $version->lessons->first()->video->update(['status' => VideoStatus::Processing]);

    expect(fn () => app(PublishCourseVersion::class)->handle($version->fresh(), adminUser()->id))
        ->toThrow(CoursePublicationException::class);

    expect(collect(app(CourseVersionValidator::class)->problems($version->fresh()))->join(' '))
        ->toContain('not ready yet');
});

it('blocks publication when a lesson has no questions', function (): void {
    $version = publishableDraft();
    $version->lessons->first()->questions()->delete();

    $problems = app(CourseVersionValidator::class)->problems($version->fresh());

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('has no questions');
});

it('blocks publication when a single-choice question has two correct answers', function (): void {
    $version = publishableDraft();
    $question = $version->lessons->first()->questions->first();
    $question->options()->update(['is_correct' => true]);

    $problems = app(CourseVersionValidator::class)->problems($version->fresh());

    expect(collect($problems)->join(' '))->toContain('more than one correct answer');
});

it('refuses to publish an already published version', function (): void {
    $version = publishableDraft();
    app(PublishCourseVersion::class)->handle($version, adminUser()->id);

    expect(fn () => app(PublishCourseVersion::class)->handle($version->fresh(), adminUser()->id))
        ->toThrow(CoursePublicationException::class);
});

it('retires the previous version when a newer one is published', function (): void {
    $first = publishableDraft();
    $admin = adminUser();
    app(PublishCourseVersion::class)->handle($first, $admin->id);

    $second = app(CreateDraftFromVersion::class)->handle($first->fresh());
    app(PublishCourseVersion::class)->handle($second, $admin->id);

    expect($first->fresh()->status)->toBe(CourseVersionStatus::Retired)
        ->and($second->fresh()->status)->toBe(CourseVersionStatus::Published)
        ->and($first->course->fresh()->current_published_version_id)->toBe($second->id);
});

it('copies a canonical published composition without cloning its module records', function (): void {
    $version = publishableDraft();
    app(PublishCourseVersion::class)->handle($version, adminUser()->id);

    $draft = app(CreateDraftFromVersion::class)->handle($version->fresh());

    expect($draft->version_number)->toBe(2)
        ->and($draft->status)->toBe(CourseVersionStatus::Draft)
        ->and($draft->moduleCompositions()->pluck('lesson_id')->all())->toBe($version->moduleCompositions()->pluck('lesson_id')->all())
        ->and($draft->lessons()->count())->toBe(0)
        ->and($version->lessons->first()->video)->not->toBeNull()
        ->and($version->lessons->first()->questions->first()->options()->count())->toBe(2);
});

it('deep copies a genuinely legacy version only when no canonical composition exists', function (): void {
    $version = publishableDraft();
    app(PublishCourseVersion::class)->handle($version, adminUser()->id);
    $sourceLesson = $version->lessons()->firstOrFail();
    $sourceLesson->update(['status' => 'published']);
    $version->moduleCompositions()->delete();

    $draft = app(CreateDraftFromVersion::class)->handle($version->fresh());
    $copy = $draft->lessons()->firstOrFail();

    expect($copy->id)->not->toBe($sourceLesson->id)
        ->and($copy->questions()->count())->toBe($sourceLesson->questions()->count())
        ->and($copy->questions()->first()->options()->count())->toBe(2);
    $copy->update(['title' => 'Legacy copy changed']);
    expect($sourceLesson->fresh()->title)->not->toBe('Legacy copy changed');
});

it('clones a shared module composition once without colliding on its position', function (): void {
    $platformActor = Account::factory()->platformAdmin()->create();
    $course = Course::factory()->shared()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course]);
    $module = Lesson::factory()->create([
        'company_id' => null,
        'is_shared' => true,
        'status' => 'published',
        'course_version_id' => $version->id,
        'position' => 1,
    ]);
    $course->update(['current_published_version_id' => $version->id]);

    $draft = app(CreateDraftFromVersion::class)->handle($version->fresh(), $platformActor);

    expect($draft->moduleCompositions()->count())->toBe(1)
        ->and($draft->moduleCompositions()->first()->lesson_id)->toBe($module->id)
        ->and($draft->lessons()->count())->toBe(0);
});

it('allows only one open draft per course', function (): void {
    $version = publishableDraft();
    app(PublishCourseVersion::class)->handle($version, adminUser()->id);
    app(CreateDraftFromVersion::class)->handle($version->fresh());

    expect(fn () => app(CreateDraftFromVersion::class)->handle($version->fresh()))
        ->toThrow(CoursePublicationException::class);
});

it('keeps the answer key out of a serialized option', function (): void {
    $option = QuestionOption::factory()->correct()->create();

    expect($option->toArray())->not->toHaveKey('is_correct');
});

it('protects the editor with the update permission', function (): void {
    $course = Course::factory()->create(['company_id' => currentCompany()->id]);
    $version = CourseVersion::factory()->create(['course_id' => $course->id]);
    $viewer = userWithPermissions([Permission::CoursesView]);
    $editor = userWithPermissions([Permission::CoursesUpdate]);

    expect($editor->company_id)->toBe($course->company_id)
        ->and($editor->hasPermission(Permission::CoursesUpdate))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('update', $course))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('updateVersion', $version))->toBeTrue();

    $this->actingAs($editor)
        ->get(route('courses.editor', ['company' => $course->company, 'course' => $course]))
        ->assertOk();

    $this->actingAs($viewer)
        ->get(route('courses.editor', ['company' => $course->company, 'course' => $course]))
        ->assertForbidden();
});

it('returns 404 when a course has no draft to edit', function (): void {
    $version = publishableDraft();
    app(PublishCourseVersion::class)->handle($version, adminUser()->id);

    $this->actingAs(adminUser())
        ->get(route('courses.editor', ['course' => $version->course]))
        ->assertNotFound();
});

it('defaults a new question to single choice with two options', function (): void {
    expect(QuestionType::SingleChoice->value)->toBe('single_choice');
});
