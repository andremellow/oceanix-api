<?php

use App\Actions\Courses\AddDirectCourseLesson;
use App\Actions\Courses\MutateCourseAssessmentStructure;
use App\Actions\Courses\PublishCourseVersion;
use App\Actions\Courses\RemoveDirectCourseLesson;
use App\Actions\Courses\ReorderDirectCourseContent;
use App\Actions\Videos\RequestVideoUpload;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Services\CourseEditor\EditorRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function editorActionSnapshot(): array
{
    return collect(['courses', 'course_versions', 'lessons', 'course_version_lessons', 'questions', 'question_options', 'videos', 'audit_logs'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
        ->all();
}

it('authorizes every editor write action directly for an editor', function (): void {
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $actor = adminUser();
    $revision = fn (): string => app(EditorRevision::class)->forCompanyCourse($course->fresh(), $version->fresh());
    $first = app(AddDirectCourseLesson::class)->handle($version, $actor, $revision());
    $second = app(AddDirectCourseLesson::class)->handle($version, $actor, $revision());

    $questionId = app(MutateCourseAssessmentStructure::class)->handle($version, $actor, 'add_question', $first->id, null, $revision());
    app(ReorderDirectCourseContent::class)->handle($version, $actor, 'lessons', null, [$second->id, $first->id], $revision());
    app(RemoveDirectCourseLesson::class)->handle($version, $actor, $second->id, $revision());

    expect(Question::query()->whereKey($questionId)->exists())->toBeTrue()
        ->and($version->lessons()->pluck('id')->all())->toBe([$first->id]);
});

it('denies every editor write action directly after permission is absent without any write', function (): void {
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $lesson = Lesson::factory()->create(['course_version_id' => $version, 'position' => 1]);
    $question = Question::factory()->create(['lesson_id' => $lesson]);
    QuestionOption::factory()->create(['question_id' => $question, 'position' => 1]);
    QuestionOption::factory()->create(['question_id' => $question, 'position' => 2]);
    $actor = userWithPermissions([Permission::CoursesView]);
    $before = editorActionSnapshot();

    $attempts = [
        fn () => app(AddDirectCourseLesson::class)->handle($version, $actor, 'current-revision'),
        fn () => app(RemoveDirectCourseLesson::class)->handle($version, $actor, $lesson->id, 'current-revision'),
        fn () => app(MutateCourseAssessmentStructure::class)->handle($version, $actor, 'add_question', $lesson->id, null, 'current-revision'),
        fn () => app(ReorderDirectCourseContent::class)->handle($version, $actor, 'lessons', null, [$lesson->id], 'current-revision'),
        fn () => app(RequestVideoUpload::class)->forCompanyEditor($lesson, $actor, 'current-revision'),
    ];

    foreach ($attempts as $attempt) {
        expect($attempt)->toThrow(AuthorizationException::class);
        expect(editorActionSnapshot())->toBe($before);
    }
});

it('rejects every immediate capability against a published version without writes', function (): void {
    $course = Course::factory()->create();
    $version = CourseVersion::factory()->published()->create(['course_id' => $course]);
    $lesson = Lesson::factory()->create(['course_version_id' => $version, 'position' => 1]);
    $question = Question::factory()->create(['lesson_id' => $lesson, 'position' => 1]);
    QuestionOption::factory()->correct()->create(['question_id' => $question, 'position' => 1]);
    QuestionOption::factory()->create(['question_id' => $question, 'position' => 2]);
    $actor = adminUser();
    $before = editorActionSnapshot();

    $attempts = [
        fn () => app(AddDirectCourseLesson::class)->handle($version, $actor, 'current-revision'),
        fn () => app(RemoveDirectCourseLesson::class)->handle($version, $actor, $lesson->id, 'current-revision'),
        fn () => app(MutateCourseAssessmentStructure::class)->handle($version, $actor, 'add_question', $lesson->id, null, 'current-revision'),
        fn () => app(ReorderDirectCourseContent::class)->handle($version, $actor, 'lessons', null, [$lesson->id], 'current-revision'),
        fn () => app(RequestVideoUpload::class)->forCompanyEditor($lesson, $actor, 'current-revision'),
    ];

    foreach ($attempts as $attempt) {
        expect($attempt)->toThrow(AuthorizationException::class);
        expect(editorActionSnapshot())->toBe($before);
    }
});

it('rejects foreign record identifiers at every applicable editor action boundary without writes', function (): void {
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $lesson = Lesson::factory()->create(['course_version_id' => $version]);
    $question = Question::factory()->create(['lesson_id' => $lesson]);
    QuestionOption::factory()->create(['question_id' => $question, 'position' => 1]);
    $foreignLesson = Lesson::factory()->create();
    $foreignQuestion = Question::factory()->create(['lesson_id' => $foreignLesson]);
    $actor = adminUser();
    $before = editorActionSnapshot();
    $revision = app(EditorRevision::class)->forCompanyCourse($course, $version);

    expect(fn () => app(RemoveDirectCourseLesson::class)->handle($version, $actor, $foreignLesson->id, $revision))->toThrow(NotFoundHttpException::class);
    expect(editorActionSnapshot())->toBe($before);
    expect(fn () => app(MutateCourseAssessmentStructure::class)->handle($version, $actor, 'remove_question', $lesson->id, $foreignQuestion->id, $revision))->toThrow(ModelNotFoundException::class);
    expect(editorActionSnapshot())->toBe($before);
    expect(fn () => app(ReorderDirectCourseContent::class)->handle($version, $actor, 'questions', $lesson->id, [$foreignQuestion->id], $revision))->toThrow(ValidationException::class, __('ui.stale_order'));
    expect(editorActionSnapshot())->toBe($before);
});

it('denies direct publication to an update-only editor without changing any publication state', function (): void {
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $lesson = Lesson::factory()->create(['course_version_id' => $version, 'position' => 1, 'content_markdown' => 'Publishable text']);
    $question = Question::factory()->create(['lesson_id' => $lesson, 'position' => 1]);
    QuestionOption::factory()->correct()->create(['question_id' => $question, 'position' => 1]);
    QuestionOption::factory()->create(['question_id' => $question, 'position' => 2]);
    $actor = userWithPermissions([Permission::CoursesView, Permission::CoursesUpdate]);
    $before = editorActionSnapshot();

    expect(fn () => app(PublishCourseVersion::class)->handle($version, $actor))
        ->toThrow(AuthorizationException::class);

    expect(editorActionSnapshot())->toBe($before)
        ->and($course->fresh()->current_published_version_id)->toBeNull()
        ->and($version->fresh()->status->value)->toBe('draft');
});
