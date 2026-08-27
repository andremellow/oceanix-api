<?php

use App\Actions\Modules\CreateModule;
use App\Actions\Modules\CreateModuleDraft;
use App\Actions\Modules\PublishModuleVersion;
use App\Jobs\PropagateSharedModuleToCourse;
use App\Models\Account;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SharedContentPropagation;
use App\Models\Video;
use App\Services\Modules\ModulePropagationImpact;
use App\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('seeds deterministic shared content and cross-company associations without external calls', function (): void {
    $this->seed(DatabaseSeeder::class);

    $course = Course::query()->where('code', 'GLOBAL-EMERGENCY')->sole();

    expect($course->is_shared)->toBeTrue()
        ->and($course->company_id)->toBeNull()
        ->and($course->companyAssociations()->withoutGlobalScopes()->count())->toBe(2)
        ->and($course->currentPublishedVersion->moduleCompositions()->count())->toBe(1);
});

it('summarizes and fans out propagation across one hundred companies without query growth', function (): void {
    Queue::fake();
    $actor = Account::factory()->platformAdmin()->create();
    $module = app(CreateModule::class)->handle($actor, 'SCALE-100', 'Scale module');
    $lesson = $module->versions()->sole();
    Video::query()->create(['company_id' => null, 'lesson_id' => $lesson->id, 'provider_asset_id' => 'scale-video', 'status' => 'ready']);
    $question = Question::query()->create(['company_id' => null, 'lesson_id' => $lesson->id, 'prompt' => 'Ready?', 'position' => 1]);
    QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'Yes', 'is_correct' => true, 'position' => 1]);
    QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'No', 'is_correct' => false, 'position' => 2]);
    $published = app(PublishModuleVersion::class)->handle($module->versions()->sole(), $actor);

    foreach (range(1, 100) as $number) {
        $company = Company::factory()->create(['slug' => sprintf('scale-%03d', $number)]);
        app(TenantContext::class)->set($company);
        $course = Course::factory()->create(['code' => sprintf('SCALE-%03d', $number)]);
        $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
        CourseVersionModule::query()->create(['course_version_id' => $version->id, 'module_version_id' => $published->id, 'position' => 1, 'is_required' => true]);
        $course->update(['current_published_version_id' => $version->id]);
    }

    $draft = app(CreateModuleDraft::class)->handle($published, $actor);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $impact = app(ModulePropagationImpact::class)->summarize($draft);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($impact['affected_courses'])->toBe(100)
        ->and($queryCount)->toBeLessThanOrEqual(8);

    app(PublishModuleVersion::class)->handle($draft, $actor);
    $propagation = SharedContentPropagation::query()->sole();

    expect($propagation->affected_count)->toBe(100)
        ->and($propagation->items()->count())->toBe(100);
    Queue::assertPushed(PropagateSharedModuleToCourse::class, 100);
});
