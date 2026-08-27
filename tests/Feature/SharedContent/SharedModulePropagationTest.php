<?php

use App\Actions\Courses\CreatePropagatedCourseVersion;
use App\Actions\Courses\PublishCourseVersion;
use App\Actions\Courses\UpdateCourseModuleComposition;
use App\Actions\Modules\CreateModule;
use App\Actions\Modules\CreateModuleDraft;
use App\Actions\Modules\PublishModuleVersion;
use App\Enums\CourseVersionStatus;
use App\Enums\SharedContentPropagationItemStatus;
use App\Enums\SharedContentPropagationStatus;
use App\Jobs\PropagateSharedModuleToCourse;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SharedContentPropagation;
use App\Models\Video;
use Illuminate\Support\Facades\Queue;

function propagatedCourseFixture(bool $withDraft = false): array
{
    $actor = Account::factory()->platformAdmin()->create();
    $module = app(CreateModule::class)->handle($actor, 'PROP-'.fake()->unique()->numerify('###'), 'Propagation module');
    $lesson = $module->versions()->sole();
    Video::query()->create(['company_id' => null, 'lesson_id' => $lesson->id, 'provider_asset_id' => fake()->uuid(), 'status' => 'ready']);
    $question = Question::query()->create(['company_id' => null, 'lesson_id' => $lesson->id, 'prompt' => 'Question?', 'position' => 1]);
    QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'Yes', 'is_correct' => true, 'position' => 1]);
    QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'No', 'is_correct' => false, 'position' => 2]);
    $firstModuleVersion = app(PublishModuleVersion::class)->handle($module->versions()->sole(), $actor);

    $course = Course::factory()->draft()->create();
    $courseVersion = CourseVersion::factory()->create(['course_id' => $course->id]);
    $user = adminUser();
    app(UpdateCourseModuleComposition::class)->handle($courseVersion, [$firstModuleVersion->id], $user);
    $publishedCourse = app(PublishCourseVersion::class)->handle($courseVersion, $user);

    $existingDraft = $withDraft
        ? CourseVersion::factory()->create(['course_id' => $course->id, 'version_number' => 2, 'title' => 'Company draft'])
        : null;
    $nextModuleVersion = app(CreateModuleDraft::class)->handle($firstModuleVersion, $actor);

    return [$actor, $module, $nextModuleVersion, $course, $publishedCourse, $existingDraft];
}

it('fans out a shared module publication and preserves an existing company draft', function (): void {
    Queue::fake();
    [$actor, $module, $nextModuleVersion, $course, $source, $existingDraft] = propagatedCourseFixture(true);

    app(PublishModuleVersion::class)->handle($nextModuleVersion, $actor);
    $propagation = SharedContentPropagation::query()->sole();
    $item = $propagation->items()->sole();

    Queue::assertPushed(PropagateSharedModuleToCourse::class, fn ($job) => $job->itemId === $item->id);
    (new PropagateSharedModuleToCourse($item->id))
        ->handle(app(CreatePropagatedCourseVersion::class));

    $result = $item->fresh()->resultCourseVersion;
    expect($result->status)->toBe(CourseVersionStatus::Published)
        ->and($result->source_course_version_id)->toBe($source->id)
        ->and($result->moduleCompositions()->sole()->module_version_id)->toBe($nextModuleVersion->id)
        ->and($course->fresh()->current_published_version_id)->toBe($result->id)
        ->and($existingDraft->fresh()->status)->toBe(CourseVersionStatus::Draft)
        ->and($existingDraft->fresh()->title)->toBe('Company draft')
        ->and($propagation->fresh()->status)->toBe(SharedContentPropagationStatus::Completed);
});

it('is retry safe after a propagation item already succeeded', function (): void {
    Queue::fake();
    [$actor, , $nextModuleVersion, $course] = propagatedCourseFixture();
    app(PublishModuleVersion::class)->handle($nextModuleVersion, $actor);
    $item = SharedContentPropagation::query()->sole()->items()->sole();
    $job = new PropagateSharedModuleToCourse($item->id);

    $job->handle(app(CreatePropagatedCourseVersion::class));
    $resultId = $item->fresh()->result_course_version_id;
    $job->handle(app(CreatePropagatedCourseVersion::class));

    expect($course->versions()->where('publication_kind', 'shared_propagation')->count())->toBe(1)
        ->and($item->fresh()->result_course_version_id)->toBe($resultId)
        ->and($item->fresh()->status)->toBe(SharedContentPropagationItemStatus::Succeeded);
});

it('records a sanitized item failure without losing the propagation run', function (): void {
    Queue::fake();
    [$actor, , $nextModuleVersion] = propagatedCourseFixture();
    app(PublishModuleVersion::class)->handle($nextModuleVersion, $actor);
    $propagation = SharedContentPropagation::query()->sole();
    $item = $propagation->items()->sole();
    $item->sourceCourseVersion->moduleCompositions()->delete();

    expect(fn () => (new PropagateSharedModuleToCourse($item->id))
        ->handle(app(CreatePropagatedCourseVersion::class)))
        ->toThrow(LogicException::class);

    expect($item->fresh()->status)->toBe(SharedContentPropagationItemStatus::Failed)
        ->and($item->fresh()->last_error)->toContain('no longer needs')
        ->and($propagation->fresh()->status)->toBe(SharedContentPropagationStatus::CompletedWithFailures)
        ->and($propagation->fresh()->failed_count)->toBe(1);
});
