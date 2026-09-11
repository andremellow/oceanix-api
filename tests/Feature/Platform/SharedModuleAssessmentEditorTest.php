<?php

use App\Models\Account;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;

/** @return array{EditorFixture, Testable} */
function legacyPlatformEditor(string $context): array
{
    $case = EditorContextCase::all()[$context];
    $fixture = EditorFixture::create($case);
    test()->withSession($fixture->session);

    return [$fixture, Livewire::test($case->component, $fixture->routeParameters())];
}

it('saves and reloads the complete assessment from the shared course editor', function (): void {
    [$fixture, $editor] = legacyPlatformEditor('shared course');
    $question = Question::query()->where('lesson_id', $fixture->recordIds[0])->with('options')->firstOrFail();
    $first = $question->options[0];
    $second = $question->options[1];

    $editor->set('records.0.questions.0.prompt', $fixture->token.' Saved question')
        ->set('records.0.questions.0.options.0.is_correct', false)
        ->set('records.0.questions.0.options.1.is_correct', true)
        ->call('saveDraft', false)
        ->assertSet('saveState', 'saved');

    expect($question->fresh()->prompt)->toBe($fixture->token.' Saved question')
        ->and($first->fresh()->is_correct)->toBeFalse()
        ->and($second->fresh()->is_correct)->toBeTrue();

    Livewire::test($fixture->context->component, $fixture->routeParameters())
        ->assertSet('records.0.questions.0.id', $question->id)
        ->assertSet('records.0.questions.0.prompt', $fixture->token.' Saved question');
});

it('provides scalar content and assessment parity in the standalone shared module editor', function (): void {
    [$fixture, $editor] = legacyPlatformEditor('standalone shared module');
    $version = ModuleVersion::query()->findOrFail($fixture->recordIds[0]);
    $question = $version->questions()->firstOrFail();

    $editor->set('records.0.title', $fixture->token.' Standalone saved')
        ->set('records.0.description', 'Saved description')
        ->set('records.0.content_markdown', '<h2>Saved content</h2>')
        ->set('records.0.minimum_watch_percentage', 85)
        ->set('records.0.passing_score', 75)
        ->set('records.0.questions.0.prompt', 'Standalone saved question')
        ->call('saveDraft', false)
        ->assertSet('saveState', 'saved');

    expect($version->fresh()->title)->toBe($fixture->token.' Standalone saved')
        ->and($version->fresh()->description)->toBe('Saved description')
        ->and($version->fresh()->content_markdown)->toContain('Saved content')
        ->and($version->fresh()->minimum_watch_percentage)->toBe(85)
        ->and($version->fresh()->passing_score)->toBe(75)
        ->and($question->fresh()->prompt)->toBe('Standalone saved question');
});

it('rolls back every shared-course module when a later module value is invalid', function (): void {
    [$fixture, $editor] = legacyPlatformEditor('shared course');
    $first = ModuleVersion::query()->findOrFail($fixture->recordIds[0]);
    $secondQuestion = Question::query()->where('lesson_id', $fixture->recordIds[1])->with('options')->firstOrFail();
    $before = $first->title;

    $editor->set('records.0.title', $fixture->token.' Must roll back')
        ->set('records.1.questions.0.options.1.text', '')
        ->call('saveDraft', false)
        ->assertSet('saveState', 'validation-error')
        ->assertSet('editorDirty', true);

    expect($first->fresh()->title)->toBe($before)
        ->and($secondQuestion->options()->findOrFail($secondQuestion->options[1]->id)->text)->not->toBe('');
});

it('preserves staged values and rejects a stale standalone module revision', function (): void {
    [$fixture, $editor] = legacyPlatformEditor('standalone shared module');
    $version = ModuleVersion::query()->findOrFail($fixture->recordIds[0]);
    $local = $fixture->token.' Local title';
    $remote = $fixture->token.' Remote title';

    $editor->set('records.0.title', $local);
    $version->update(['title' => $remote]);
    $editor->call('saveDraft', false)
        ->assertSet('saveState', 'conflict')
        ->assertSet('editorDirty', true)
        ->assertSet('records.0.title', $local);

    expect($version->fresh()->title)->toBe($remote);
});

it('persists assessment structure immediately with stable ids in both platform contexts', function (string $context): void {
    [$fixture, $editor] = legacyPlatformEditor($context);
    $recordId = $fixture->recordIds[0];
    $before = Question::query()->where('lesson_id', $recordId)->count();

    $editor->call('addQuestion', $recordId)
        ->assertCount('records.0.questions', $before + 1)
        ->assertSet('editorDirty', false);

    $created = Question::query()->where('lesson_id', $recordId)->orderByDesc('position')->firstOrFail();
    expect($created->id)->toBeGreaterThan(0)->and($created->options()->count())->toBe(2);
})->with(['shared course', 'standalone shared module']);

it('saves a fifty-question standalone module through the atomic Save action', function (): void {
    [$fixture] = legacyPlatformEditor('standalone shared module');
    $version = ModuleVersion::query()->findOrFail($fixture->recordIds[0]);
    $version->questions()->delete();
    foreach (range(1, 50) as $position) {
        $question = Question::factory()->create(['company_id' => null, 'lesson_id' => $version->id, 'position' => $position, 'prompt' => 'Question '.$position]);
        QuestionOption::factory()->correct()->create(['company_id' => null, 'question_id' => $question->id, 'position' => 1, 'text' => 'Correct '.$position]);
        QuestionOption::factory()->create(['company_id' => null, 'question_id' => $question->id, 'position' => 2, 'text' => 'Other '.$position]);
    }

    Livewire::test($fixture->context->component, $fixture->routeParameters())
        ->assertCount('records.0.questions', 50)
        ->set('records.0.questions.49.prompt', 'Fiftieth updated')
        ->call('saveDraft', false)
        ->assertSet('saveState', 'saved');

    expect($version->questions()->where('position', 50)->firstOrFail()->prompt)->toBe('Fiftieth updated');
});

it('round trips multiple-choice answers in both platform contexts', function (string $context): void {
    [$fixture, $editor] = legacyPlatformEditor($context);
    $question = Question::query()->where('lesson_id', $fixture->recordIds[0])->with('options')->firstOrFail();

    $editor->set('records.0.questions.0.type', 'multiple_choice')
        ->set('records.0.questions.0.options.0.is_correct', true)
        ->set('records.0.questions.0.options.1.is_correct', true)
        ->set('records.0.questions.0.options.1.text', $fixture->token.' Second correct')
        ->call('saveDraft', false)
        ->assertSet('saveState', 'saved');

    expect($question->fresh()->type->value)->toBe('multiple_choice')
        ->and($question->options()->where('is_correct', true)->count())->toBe(2)
        ->and($question->options()->where('text', $fixture->token.' Second correct')->exists())->toBeTrue();
})->with(['shared course', 'standalone shared module']);

it('does not convert revoked platform access into a retryable network error', function (string $context): void {
    [$fixture, $editor] = legacyPlatformEditor($context);
    $before = ModuleVersion::query()->findOrFail($fixture->recordIds[0])->title;
    $editor->set('records.0.title', $fixture->token.' Revoked pending');
    Account::query()->findOrFail($fixture->session['platform_account_id'])->update(['is_platform_admin' => false]);

    $editor->call('saveDraft', false)
        ->assertSet('saveState', 'permission-lost')
        ->assertSet('editorDirty', true)
        ->assertSet('records.0.title', $fixture->token.' Revoked pending');

    expect(ModuleVersion::query()->findOrFail($fixture->recordIds[0])->title)->toBe($before);
})->with(['shared course', 'standalone shared module']);

it('preserves canonical media directives during a title-only Save', function (string $context): void {
    [$fixture] = legacyPlatformEditor($context);
    $version = ModuleVersion::query()->findOrFail($fixture->recordIds[0]);
    $canonical = '<p>Before</p><div data-oceanix-video></div><p>After</p>';
    $version->update(['content_markdown' => $canonical]);
    $editor = Livewire::test($fixture->context->component, $fixture->routeParameters());

    $editor->set('records.0.title', $fixture->token.' Title only')
        ->call('saveDraft', false)
        ->assertSet('saveState', 'saved');

    expect($version->fresh()->content_markdown)->toContain('data-oceanix-video')
        ->and($version->fresh()->content_markdown)->toContain('Before')
        ->and($version->fresh()->content_markdown)->toContain('After');
})->with(['shared course', 'standalone shared module']);
