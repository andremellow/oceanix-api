<?php

use App\Actions\Courses\CreateCourse;
use App\Actions\Courses\PublishCourseVersion;
use App\Enums\CourseStatus;
use App\Enums\QuestionType;
use App\Models\Account;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;

it('creates and publishes a shared course with platform-account provenance', function (): void {
    $actor = Account::factory()->platformAdmin()->create();
    $course = app(CreateCourse::class)->handle('global-01', 'Global safety', platformActor: $actor);
    $version = $course->versions()->sole();
    $lesson = Lesson::query()->create([
        'company_id' => null, 'course_version_id' => $version->id, 'is_shared' => true,
        'status' => 'published', 'title' => 'Introduction', 'position' => 1, 'is_required' => true,
    ]);
    Video::query()->create([
        'company_id' => null, 'lesson_id' => $lesson->id, 'provider_asset_id' => 'shared-video',
        'status' => 'ready',
    ]);
    $question = Question::query()->create([
        'company_id' => null, 'lesson_id' => $lesson->id, 'type' => QuestionType::SingleChoice,
        'prompt' => 'Ready?', 'position' => 1,
    ]);
    QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'Yes', 'is_correct' => true, 'position' => 1]);
    QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'No', 'is_correct' => false, 'position' => 2]);

    $published = app(PublishCourseVersion::class)->handle($version, $actor);

    expect($course->company_id)->toBeNull()
        ->and($course->is_shared)->toBeTrue()
        ->and($published->published_by)->toBeNull()
        ->and($published->published_by_account_id)->toBe($actor->id)
        ->and($course->fresh()->status)->toBe(CourseStatus::Active);
});

it('requires a platform administrator account to publish a shared course', function (): void {
    $actor = Account::factory()->platformAdmin()->create();
    $course = app(CreateCourse::class)->handle('GLOBAL-02', 'Global safety', platformActor: $actor);

    expect(fn () => app(PublishCourseVersion::class)->handle($course->versions()->sole(), 123))
        ->toThrow(LogicException::class);
});
