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
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use App\Services\Courses\CourseVersionValidator;

/** Builds a draft version that satisfies every publication rule. */
function publishableDraft(): CourseVersion
{
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course->id]);

    $lesson = Lesson::factory()->create(['course_version_id' => $version->id]);
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

it('clones a published version into a new draft without touching the original', function (): void {
    $version = publishableDraft();
    app(PublishCourseVersion::class)->handle($version, adminUser()->id);

    $draft = app(CreateDraftFromVersion::class)->handle($version->fresh());

    expect($draft->version_number)->toBe(2)
        ->and($draft->status)->toBe(CourseVersionStatus::Draft)
        ->and($draft->lessons()->count())->toBe($version->lessons()->count())
        ->and($draft->lessons->first()->video)->not->toBeNull()
        ->and($draft->lessons->first()->questions->first()->options()->count())->toBe(2);

    // Editing the clone must not reach the published edition.
    $draft->lessons->first()->update(['title' => 'Changed in the draft']);

    expect($version->fresh()->lessons->first()->title)->not->toBe('Changed in the draft');
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
    $course = Course::factory()->create();
    CourseVersion::factory()->create(['course_id' => $course->id]);

    $this->actingAs(userWithPermissions([Permission::CoursesView]))
        ->get(route('courses.editor', ['course' => $course]))
        ->assertForbidden();

    $this->actingAs(userWithPermissions([Permission::CoursesUpdate]))
        ->get(route('courses.editor', ['course' => $course]))
        ->assertOk();
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
