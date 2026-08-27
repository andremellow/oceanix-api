<?php

use App\Actions\Courses\CreatePropagatedCourseVersion;
use App\Actions\Courses\PublishCourseVersion;
use App\Actions\Courses\UpdateCourseModuleComposition;
use App\Actions\Modules\CreateModule;
use App\Actions\Modules\CreateModuleDraft;
use App\Actions\Modules\PublishModuleVersion;
use App\Jobs\PropagateSharedModuleToCourse;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SharedContentPropagation;
use App\Models\Video;
use Illuminate\Support\Facades\Queue;

function concurrencyPropagationFixture(): array
{
    $actor = Account::factory()->platformAdmin()->create();
    $module = app(CreateModule::class)->handle($actor, 'CONCURRENT', 'Concurrent module');
    $lesson = $module->versions()->sole();
    Video::query()->create(['company_id' => null, 'lesson_id' => $lesson->id, 'provider_asset_id' => fake()->uuid(), 'status' => 'ready']);
    $question = Question::query()->create(['company_id' => null, 'lesson_id' => $lesson->id, 'prompt' => 'Question?', 'position' => 1]);
    QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'Yes', 'is_correct' => true, 'position' => 1]);
    QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'No', 'is_correct' => false, 'position' => 2]);
    $first = app(PublishModuleVersion::class)->handle($module->versions()->sole(), $actor);
    $course = Course::factory()->draft()->create();
    $courseVersion = CourseVersion::factory()->create(['course_id' => $course->id]);
    $user = adminUser();
    app(UpdateCourseModuleComposition::class)->handle($courseVersion, [$first->id], $user);
    app(PublishCourseVersion::class)->handle($courseVersion, $user);

    return [$actor, app(CreateModuleDraft::class)->handle($first, $actor), $course];
}

it('allocates only one propagated version when the same item is handled twice', function (): void {
    Queue::fake();
    [$actor, $nextModuleVersion, $course] = concurrencyPropagationFixture();
    app(PublishModuleVersion::class)->handle($nextModuleVersion, $actor);
    $item = SharedContentPropagation::query()->sole()->items()->sole();

    (new PropagateSharedModuleToCourse($item->id))->handle(app(CreatePropagatedCourseVersion::class));
    (new PropagateSharedModuleToCourse($item->id))->handle(app(CreatePropagatedCourseVersion::class));

    expect($course->versions()->where('publication_kind', 'shared_propagation')->count())->toBe(1);
});

it('does not downgrade a course when propagation jobs arrive out of publication order', function (): void {
    Queue::fake();
    [$actor, $second, $course] = concurrencyPropagationFixture();
    app(PublishModuleVersion::class)->handle($second, $actor);
    $third = app(CreateModuleDraft::class)->handle($second->fresh(), $actor);
    app(PublishModuleVersion::class)->handle($third, $actor);
    $items = SharedContentPropagation::query()->with('items')->orderBy('id')->get()->flatMap->items->values();

    (new PropagateSharedModuleToCourse($items[1]->id))->handle(app(CreatePropagatedCourseVersion::class));
    (new PropagateSharedModuleToCourse($items[0]->id))->handle(app(CreatePropagatedCourseVersion::class));

    expect($course->fresh()->currentPublishedVersion->moduleCompositions()->sole()->module_version_id)->toBe($third->id)
        ->and($course->versions()->where('publication_kind', 'shared_propagation')->count())->toBe(1);
});
