<?php

use App\Actions\Modules\CreateModule;
use App\Actions\Modules\CreateModuleDraft;
use App\Actions\Modules\PublishModuleVersion;
use App\Enums\ModuleVersionStatus;
use App\Models\Account;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;

function addPublishableModuleLesson(ModuleVersion $version): void
{
    $lesson = $version;
    Video::query()->create(['company_id' => null, 'lesson_id' => $lesson->id, 'provider_asset_id' => fake()->uuid(), 'status' => 'ready']);
    $question = Question::query()->create(['company_id' => null, 'lesson_id' => $lesson->id, 'prompt' => 'Question?', 'position' => 1]);
    QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'Yes', 'is_correct' => true, 'position' => 1]);
    QuestionOption::query()->create(['company_id' => null, 'question_id' => $question->id, 'text' => 'No', 'is_correct' => false, 'position' => 2]);
}

it('creates and publishes a platform-owned shared module with explicit account provenance', function (): void {
    $actor = Account::factory()->platformAdmin()->create();
    $module = app(CreateModule::class)->handle($actor, 'safe-01', 'Safety basics');
    $version = $module->versions()->sole();
    addPublishableModuleLesson($version);

    expect($module->company_id)->toBeNull()
        ->and($module->is_shared)->toBeTrue()
        ->and($version->status)->toBe(ModuleVersionStatus::Draft)
        ->and($version->published_by_account_id)->toBe($actor->id);

    $published = app(PublishModuleVersion::class)->handle($version, $actor);

    expect($published->status)->toBe(ModuleVersionStatus::Published)
        ->and($published->published_by_account_id)->toBe($actor->id)
        ->and($module->fresh()->getRawOriginal('status'))->toBe(ModuleVersionStatus::Published->value);
});

it('creates a separate draft and leaves a published module version immutable', function (): void {
    $actor = Account::factory()->platformAdmin()->create();
    $module = app(CreateModule::class)->handle($actor, 'IMM-01', 'Immutable');
    addPublishableModuleLesson($module->versions()->sole());
    $published = app(PublishModuleVersion::class)->handle($module->versions()->sole(), $actor);
    $draft = app(CreateModuleDraft::class)->handle($published, $actor);

    $draft->update(['title' => 'New title']);

    expect($draft->version_number)->toBe(2)
        ->and($draft->status)->toBe(ModuleVersionStatus::Draft)
        ->and($published->fresh()->title)->toBe('Immutable')
        ->and(ModuleVersion::query()->where('lineage_uuid', $module->lineage_uuid)->count())->toBe(2);
});

it('rejects shared authoring by a non-platform account', function (): void {
    $account = Account::factory()->create();

    expect(fn () => app(CreateModule::class)->handle($account, 'DENIED', 'Denied'))
        ->toThrow(LogicException::class);
});
