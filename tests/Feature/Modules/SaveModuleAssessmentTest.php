<?php

use App\Actions\Modules\SaveModuleAssessment;
use App\Models\Account;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Validation\ValidationException;

function assessmentFixture(): array
{
    $actor = Account::factory()->platformAdmin()->create();
    $module = Module::factory()->shared()->create(['status' => 'draft']);
    $version = ModuleVersion::query()->findOrFail($module->id);
    $question = Question::factory()->create(['lesson_id' => $version->id, 'company_id' => null, 'type' => 'single_choice', 'prompt' => 'Original question']);
    $first = QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'company_id' => null, 'position' => 1, 'text' => 'First']);
    $second = QuestionOption::factory()->create(['question_id' => $question->id, 'company_id' => null, 'position' => 2, 'text' => 'Second']);

    return [$actor, $version, $question, $first, $second, [
        'questions' => [[
            'id' => $question->id,
            'prompt' => 'Updated question',
            'type' => 'single_choice',
            'max_attempts' => 4,
            'options' => [
                ['id' => $first->id, 'text' => 'Updated first', 'is_correct' => false],
                ['id' => $second->id, 'text' => 'Updated second', 'is_correct' => true],
            ],
        ]],
    ]];
}

it('saves a complete assessment atomically and advances its revision', function (): void {
    [$actor, $version, $question, $first, $second, $payload] = assessmentFixture();
    $action = app(SaveModuleAssessment::class);
    $revision = $action->revision($version);

    $nextRevision = $action->handle($version, $actor, $payload, $revision);

    expect($nextRevision)->not->toBe($revision)
        ->and($question->fresh()->prompt)->toBe('Updated question')
        ->and($question->fresh()->max_attempts)->toBe(4)
        ->and($first->fresh()->text)->toBe('Updated first')
        ->and($first->fresh()->is_correct)->toBeFalse()
        ->and($second->fresh()->is_correct)->toBeTrue();
});

it('rejects invalid and foreign answer payloads without partial writes', function (): void {
    [$actor, $version, $question, $first, $second, $payload] = assessmentFixture();
    $action = app(SaveModuleAssessment::class);
    $revision = $action->revision($version);
    $payload['questions'][0]['options'][1]['is_correct'] = false;

    expect(fn () => $action->handle($version, $actor, $payload, $revision))->toThrow(ValidationException::class);
    expect($question->fresh()->prompt)->toBe('Original question')->and($first->fresh()->text)->toBe('First');

    $foreignQuestion = Question::factory()->create();
    $foreignOption = QuestionOption::factory()->create(['question_id' => $foreignQuestion]);
    $payload['questions'][0]['options'][1] = ['id' => $foreignOption->id, 'text' => 'Foreign', 'is_correct' => true];

    expect(fn () => $action->handle($version, $actor, $payload, $revision))->toThrow(ValidationException::class);
    expect($question->fresh()->prompt)->toBe('Original question')->and($second->fresh()->text)->toBe('Second');
});

it('rejects stale revisions without overwriting newer assessment data', function (): void {
    [$actor, $version, $question, $first, $second, $payload] = assessmentFixture();
    $action = app(SaveModuleAssessment::class);
    $staleRevision = $action->revision($version);
    $question->update(['prompt' => 'A concurrent assessment edit']);

    expect(fn () => $action->handle($version, $actor, $payload, $staleRevision))->toThrow(ValidationException::class);
    expect($question->fresh()->prompt)->toBe('A concurrent assessment edit');
});

it('does not treat unrelated module edits as assessment conflicts across time', function (): void {
    [$actor, $version, $question, $first, $second, $payload] = assessmentFixture();
    $action = app(SaveModuleAssessment::class);
    $revision = $action->revision($version);

    $this->travel(2)->seconds();
    $version->update(['title' => 'Unrelated module title edit']);

    expect($action->handle($version, $actor, $payload, $revision))->not->toBe($revision)
        ->and($question->fresh()->prompt)->toBe('Updated question');
});

it('rejects revoked actors and module versions outside a platform shared draft', function (): void {
    [$actor, $version, $question, $first, $second, $payload] = assessmentFixture();
    $action = app(SaveModuleAssessment::class);
    $revision = $action->revision($version);
    $actor->update(['is_platform_admin' => false]);

    expect(fn () => $action->handle($version, $actor, $payload, $revision))->toThrow(LogicException::class);

    $activeActor = Account::factory()->platformAdmin()->create();
    $version->update(['status' => 'published']);
    expect(fn () => $action->handle($version, $activeActor, $payload, $action->revision($version->fresh())))->toThrow(LogicException::class);

    $version->update(['status' => 'draft', 'is_shared' => false, 'company_id' => Company::factory()->create()->id]);
    expect(fn () => $action->handle($version, $activeActor, $payload, $action->revision($version->fresh())))->toThrow(LogicException::class);
    expect($question->fresh()->prompt)->toBe('Original question');
});
