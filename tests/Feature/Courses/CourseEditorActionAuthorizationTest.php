<?php

use App\Actions\Courses\AddDirectCourseLesson;
use App\Actions\Courses\MutateCourseAssessmentStructure;
use App\Actions\Courses\RemoveDirectCourseLesson;
use App\Actions\Courses\ReorderDirectCourseContent;
use App\Actions\Courses\UpdateCourseEditorField;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function editorActionSnapshot(): array
{
    return collect(['courses', 'course_versions', 'lessons', 'course_version_lessons', 'questions', 'question_options'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
        ->all();
}

it('authorizes every editor write action directly for an editor', function (): void {
    $course = Course::factory()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    $actor = adminUser();
    $first = app(AddDirectCourseLesson::class)->handle($version, $actor);
    $second = app(AddDirectCourseLesson::class)->handle($version, $actor);

    app(UpdateCourseEditorField::class)->handle($version, $actor, 'lesson', $first->id, 'title', 'Authorized title');
    $questionId = app(MutateCourseAssessmentStructure::class)->handle($version, $actor, 'add_question', $first->id);
    app(ReorderDirectCourseContent::class)->handle($version, $actor, 'lessons', null, [$second->id, $first->id]);
    app(RemoveDirectCourseLesson::class)->handle($version, $actor, $second->id);

    expect($first->fresh()->title)->toBe('Authorized title')
        ->and(Question::query()->whereKey($questionId)->exists())->toBeTrue()
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
        fn () => app(AddDirectCourseLesson::class)->handle($version, $actor),
        fn () => app(RemoveDirectCourseLesson::class)->handle($version, $actor, $lesson->id),
        fn () => app(UpdateCourseEditorField::class)->handle($version, $actor, 'lesson', $lesson->id, 'title', 'Forbidden'),
        fn () => app(MutateCourseAssessmentStructure::class)->handle($version, $actor, 'add_question', $lesson->id),
        fn () => app(ReorderDirectCourseContent::class)->handle($version, $actor, 'lessons', null, [$lesson->id]),
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

    expect(fn () => app(RemoveDirectCourseLesson::class)->handle($version, $actor, $foreignLesson->id))->toThrow(ModelNotFoundException::class);
    expect(editorActionSnapshot())->toBe($before);
    expect(fn () => app(UpdateCourseEditorField::class)->handle($version, $actor, 'lesson', $foreignLesson->id, 'title', 'Foreign'))->toThrow(ModelNotFoundException::class);
    expect(editorActionSnapshot())->toBe($before);
    expect(fn () => app(MutateCourseAssessmentStructure::class)->handle($version, $actor, 'remove_question', $lesson->id, $foreignQuestion->id))->toThrow(ModelNotFoundException::class);
    expect(editorActionSnapshot())->toBe($before);
    expect(fn () => app(ReorderDirectCourseContent::class)->handle($version, $actor, 'questions', $lesson->id, [$foreignQuestion->id]))->toThrow(ValidationException::class, __('ui.stale_order'));
    expect(editorActionSnapshot())->toBe($before);
});
