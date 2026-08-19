<?php

use App\Enums\CourseVersionStatus;
use App\Enums\Permission;
use App\Enums\QuestionType;
use App\Enums\VideoStatus;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function draftCourse(): Course
{
    $course = Course::factory()->draft()->create();
    CourseVersion::factory()->create(['course_id' => $course->id]);

    return $course->refresh();
}

it('adds a lesson and persists it immediately', function (): void {
    $course = draftCourse();

    Livewire::actingAs(adminUser())
        ->test('courses.editor', ['course' => $course])
        ->call('addLesson')
        ->assertSet('lessons.0.title', 'New lesson');

    expect($course->versions()->first()->lessons()->count())->toBe(1);
});

it('autosaves a lesson field straight to the database', function (): void {
    $course = draftCourse();
    $version = $course->versions()->first();
    $lesson = Lesson::factory()->create(['course_version_id' => $version->id]);

    Livewire::actingAs(adminUser())
        ->test('courses.editor', ['course' => $course])
        ->set('lessons.0.title', 'Gas detection and alarms')
        ->set('lessons.0.minimum_watch_percentage', 95);

    expect($lesson->fresh()->title)->toBe('Gas detection and alarms')
        ->and($lesson->fresh()->minimum_watch_percentage)->toBe(95);
});

it('rejects an out-of-range watch threshold', function (): void {
    $course = draftCourse();
    Lesson::factory()->create(['course_version_id' => $course->versions()->first()->id]);

    Livewire::actingAs(adminUser())
        ->test('courses.editor', ['course' => $course])
        ->set('lessons.0.minimum_watch_percentage', 140)
        ->assertHasErrors('lessons.0.minimum_watch_percentage');
});

it('creates a question with two options ready to fill in', function (): void {
    $course = draftCourse();
    Lesson::factory()->create(['course_version_id' => $course->versions()->first()->id]);

    Livewire::actingAs(adminUser())
        ->test('courses.editor', ['course' => $course])
        ->call('addQuestion', 0)
        ->assertCount('lessons.0.questions', 1)
        ->assertCount('lessons.0.questions.0.options', 2);
});

it('keeps exactly one correct answer on a single-choice question', function (): void {
    $course = draftCourse();
    $lesson = Lesson::factory()->create(['course_version_id' => $course->versions()->first()->id]);
    $question = Question::factory()->create(['lesson_id' => $lesson->id]);
    $first = QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'position' => 1]);
    $second = QuestionOption::factory()->create(['question_id' => $question->id, 'position' => 2]);

    Livewire::actingAs(adminUser())
        ->test('courses.editor', ['course' => $course])
        ->call('selectSingleCorrect', 0, 0, 1);

    expect($first->fresh()->is_correct)->toBeFalse()
        ->and($second->fresh()->is_correct)->toBeTrue();
});

it('reorders lessons and renumbers their positions', function (): void {
    $course = draftCourse();
    $version = $course->versions()->first();
    $first = Lesson::factory()->create(['course_version_id' => $version->id, 'title' => 'First', 'position' => 1]);
    $second = Lesson::factory()->create(['course_version_id' => $version->id, 'title' => 'Second', 'position' => 2]);

    Livewire::actingAs(adminUser())
        ->test('courses.editor', ['course' => $course])
        ->call('moveLesson', 1, -1)
        ->assertSet('lessons.0.title', 'Second');

    expect($first->fresh()->position)->toBe(2)
        ->and($second->fresh()->position)->toBe(1);
});

it('refuses to touch a lesson that belongs to another course version', function (): void {
    $course = draftCourse();
    Lesson::factory()->create(['course_version_id' => $course->versions()->first()->id]);
    $foreign = Lesson::factory()->create();

    // Ids arriving from the browser are untrusted: swap in a foreign lesson id.
    Livewire::actingAs(adminUser())
        ->test('courses.editor', ['course' => $course])
        ->set('lessons.0.id', $foreign->id)
        ->call('removeLesson', 0)
        ->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});

it('opens an upload slot and marks the video processing when the browser finishes', function (): void {
    // The provider is behind a contract, but this test exercises the real Cloudflare
    // implementation's request shape, so fake the HTTP layer rather than the contract.
    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'result' => ['uid' => 'asset-123', 'uploadURL' => 'https://upload.cloudflarestream.com/abc'],
        ]),
    ]);

    $course = draftCourse();
    $lesson = Lesson::factory()->create(['course_version_id' => $course->versions()->first()->id]);

    $component = Livewire::actingAs(adminUser())
        ->test('courses.editor', ['course' => $course])
        ->call('requestUpload', 0);

    expect($lesson->fresh()->video)->not->toBeNull()
        ->and($lesson->fresh()->video->status)->toBe(VideoStatus::Uploading);

    $component->call('uploadCompleted', 0);

    expect($lesson->fresh()->video->status)->toBe(VideoStatus::Processing);
});

it('publishes from the editor once every rule is satisfied', function (): void {
    $course = draftCourse();
    $version = $course->versions()->first();
    $lesson = Lesson::factory()->create(['course_version_id' => $version->id]);
    Video::factory()->create(['lesson_id' => $lesson->id]);
    $question = Question::factory()->create(['lesson_id' => $lesson->id, 'type' => QuestionType::SingleChoice]);
    QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'position' => 1]);
    QuestionOption::factory()->create(['question_id' => $question->id, 'position' => 2]);

    Livewire::actingAs(adminUser())
        ->test('courses.editor', ['course' => $course])
        ->call('confirmPublish')
        ->assertSet('publishProblems', [])
        ->call('publish')
        ->assertRedirect(route('courses.show', $course));

    expect($version->fresh()->status)->toBe(CourseVersionStatus::Published)
        ->and($course->fresh()->current_published_version_id)->toBe($version->id);
});

it('reports what is missing instead of publishing an incomplete version', function (): void {
    $course = draftCourse();
    Lesson::factory()->create(['course_version_id' => $course->versions()->first()->id]);

    Livewire::actingAs(adminUser())
        ->test('courses.editor', ['course' => $course])
        ->call('confirmPublish')
        ->assertSet('confirmingPublish', true)
        ->call('publish')
        ->assertNoRedirect();

    expect($course->versions()->first()->fresh()->status)->toBe(CourseVersionStatus::Draft);
});

it('denies editing to someone who can only view courses', function (): void {
    $course = draftCourse();

    // The guard sits in mount(), so a view-only profile never even reaches the component.
    Livewire::actingAs(userWithPermissions([Permission::CoursesView]))
        ->test('courses.editor', ['course' => $course])
        ->assertForbidden();
});

it('denies publishing to someone who can edit but not publish', function (): void {
    $course = draftCourse();

    Livewire::actingAs(userWithPermissions([Permission::CoursesUpdate]))
        ->test('courses.editor', ['course' => $course])
        ->call('confirmPublish')
        ->assertForbidden();
});

it('keeps the version title tracking the course title while it is a draft', function (): void {
    $course = draftCourse();
    $version = $course->versions()->first();

    Livewire::actingAs(adminUser())
        ->test('courses.editor', ['course' => $course])
        ->set('courseForm.title', 'Hydrogen sulphide awareness');

    expect($course->fresh()->title)->toBe('Hydrogen sulphide awareness')
        ->and($version->fresh()->title)->toBe('Hydrogen sulphide awareness');
});

it('freezes the version title when the course is renamed after publication', function (): void {
    $course = draftCourse();
    $version = $course->versions()->first();
    $lesson = Lesson::factory()->create(['course_version_id' => $version->id]);
    Video::factory()->create(['lesson_id' => $lesson->id]);
    $question = Question::factory()->create(['lesson_id' => $lesson->id]);
    QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'position' => 1]);
    QuestionOption::factory()->create(['question_id' => $question->id, 'position' => 2]);

    Livewire::actingAs(adminUser())
        ->test('courses.editor', ['course' => $course])
        ->set('courseForm.title', 'Original wording')
        ->call('publish');

    // Renaming the course afterwards must not rewrite what the published edition said.
    $course->fresh()->update(['title' => 'Renamed later']);

    expect($version->fresh()->title)->toBe('Original wording');
});
