<?php

use App\Models\Course;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use App\Services\CourseEditor\EditorRevision;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;

/** @return array{EditorFixture, Testable} */
function deepUnifiedEditor(string $contextName): array
{
    $fixture = EditorFixture::create(EditorContextCase::all()[$contextName]);

    if ($fixture->user !== null) {
        Livewire::actingAs($fixture->user);
    }
    if ($fixture->session !== []) {
        test()->withSession($fixture->session);
    }

    return [$fixture, Livewire::test($fixture->context->component, $fixture->routeParameters())];
}

/** @return array{string, Model} */
function deepTitleTarget(EditorFixture $fixture): array
{
    return $fixture->context->name === 'shared-module'
        ? ['records.0.title', ModuleVersion::query()->findOrFail($fixture->recordIds[0])]
        : ['courseForm.title', Course::query()->findOrFail($fixture->root->id)];
}

/** @return array<string, mixed> */
function deepGraphSnapshot(EditorFixture $fixture): array
{
    $recordIds = $fixture->recordIds;
    $questionIds = Question::query()->whereIn('lesson_id', $recordIds)->pluck('id');

    return [
        'root' => $fixture->root->fresh()->getAttributes(),
        'records' => Lesson::query()->whereIn('id', $recordIds)->orderBy('id')->get()->map->getAttributes()->all(),
        'questions' => Question::query()->whereIn('lesson_id', $recordIds)->orderBy('id')->get()->map->getAttributes()->all(),
        'options' => QuestionOption::query()->whereIn('question_id', $questionIds)->orderBy('id')->get()->map->getAttributes()->all(),
    ];
}

function deepCanonicalRevision(EditorFixture $fixture): string
{
    $revisions = app(EditorRevision::class);
    if ($fixture->context->name === 'shared-module') {
        return $revisions->forSharedModule(ModuleVersion::query()->findOrFail($fixture->recordIds[0]));
    }

    $course = Course::query()->findOrFail($fixture->root->id);
    $version = $course->versions()->where('status', 'draft')->firstOrFail();

    return $fixture->context->name === 'company-course'
        ? $revisions->forCompanyCourse($course, $version)
        : $revisions->forSharedCourse($course, $version);
}

it('atomically saves representative scalar rich-text question and option fields across the complete graph', function (string $contextName): void {
    [$fixture, $editor] = deepUnifiedEditor($contextName);
    [$titlePath, $titleModel] = deepTitleTarget($fixture);
    $record = Lesson::query()->findOrFail($fixture->recordIds[0]);
    $question = Question::query()->where('lesson_id', $record->id)->with('options')->orderBy('position')->firstOrFail();
    $title = $fixture->token.' Whole graph title';
    $description = $fixture->token.' record description';
    $content = '## '.$fixture->token."\n\nWhole graph rich content.";
    $prompt = $fixture->token.' whole graph question';
    $answer = $fixture->token.' whole graph answer';

    $before = deepGraphSnapshot($fixture);
    $editor->set($titlePath, $title)
        ->set('records.0.description', $description)
        ->set('records.0.content_markdown', $content)
        ->set('records.0.questions.0.prompt', $prompt)
        ->set('records.0.questions.0.options.0.text', $answer)
        ->set('records.0.questions.0.options.0.is_correct', false)
        ->set('records.0.questions.0.options.1.is_correct', true)
        ->assertSet('editorDirty', true);

    expect(deepGraphSnapshot($fixture))->toBe($before);

    $editor->call('saveDraft', false)
        ->assertHasNoErrors()
        ->assertSet('editorDirty', false)
        ->assertSet('saveState', 'saved');

    expect($titleModel->fresh()->title)->toBe($title)
        ->and($record->fresh()->description)->toBe($description)
        ->and($record->fresh()->content_markdown)->toContain('Whole graph rich content.')
        ->and($question->fresh()->prompt)->toBe($prompt)
        ->and($question->options()->findOrFail($question->options->first()->id)->text)->toBe($answer)
        ->and($question->options()->orderBy('position')->get()->pluck('is_correct')->map(fn ($value) => (bool) $value)->all())->toBe([false, true]);
})->with('course editor contexts');

it('executes an action-level no-op Save without changing any graph value revision or timestamp', function (string $contextName): void {
    [$fixture, $editor] = deepUnifiedEditor($contextName);
    $beforeGraph = deepGraphSnapshot($fixture);
    $beforeRevisions = $editor->get('revisions');
    $beforeCanonicalRevision = deepCanonicalRevision($fixture);

    expect($beforeRevisions['root'])->toBe($beforeCanonicalRevision);

    $editor->call('markEditorDirty')
        ->assertSet('editorDirty', true)
        ->call('saveDraft', false)
        ->assertHasNoErrors()
        ->assertSet('editorDirty', false)
        ->assertSet('saveState', 'saved');

    expect(deepGraphSnapshot($fixture))->toBe($beforeGraph)
        ->and(deepCanonicalRevision($fixture))->toBe($beforeCanonicalRevision)
        ->and($editor->get('revisions'))->toBe($beforeRevisions);
})->with('course editor contexts');

it('rejects temporary structural keys and preserves the exact persisted graph', function (string $contextName): void {
    [$fixture, $editor] = deepUnifiedEditor($contextName);
    $before = deepGraphSnapshot($fixture);

    $editor->set('records.0.questions.0.key', 'tmp:question-client-only')
        ->set('records.0.questions.0.prompt', $fixture->token.' must remain local')
        ->call('saveDraft', false)
        ->assertSet('editorDirty', true)
        ->assertSet('saveState', 'validation-error')
        ->assertSet('records.0.questions.0.key', 'tmp:question-client-only');

    expect(deepGraphSnapshot($fixture))->toBe($before);
})->with('course editor contexts');

it('rejects unavailable persisted identities and preserves every sibling', function (string $contextName): void {
    [$fixture, $editor] = deepUnifiedEditor($contextName);
    $before = deepGraphSnapshot($fixture);
    $expectedMessage = $fixture->context->name === 'company-course'
        ? __('One or more editor records are unavailable.')
        : __('One or more assessment answers are unavailable.');

    $editor->set('records.0.questions.0.options.1.id', 2147483647)
        ->set('records.0.questions.0.options.1.key', 'option:2147483647')
        ->set('records.0.questions.0.options.1.text', $fixture->token.' forged')
        ->call('saveDraft', false)
        ->assertSet('editorDirty', true)
        ->assertSet('saveState', 'validation-error')
        ->assertSet('errorKind', 'validation-error')
        ->assertSet('saveError', $expectedMessage)
        ->assertSet('records.0.questions.0.options.1.text', $fixture->token.' forged');

    expect(deepGraphSnapshot($fixture))->toBe($before);
})->with('course editor contexts');

it('reorders three stable questions and three stable options with exact contiguous positions', function (string $contextName): void {
    [$fixture, $editor] = deepUnifiedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $questions = Question::query()->where('lesson_id', $recordId)->orderBy('position')->get();
    $movedQuestion = $questions[2];

    $editor->call('moveQuestion', $recordId, $movedQuestion->id, -1)
        ->call('moveQuestion', $recordId, $movedQuestion->id, -1)
        ->assertSet('records.0.questions.0.id', $movedQuestion->id);

    expect(Question::query()->where('lesson_id', $recordId)->orderBy('position')->pluck('id')->all())
        ->toBe([$movedQuestion->id, $questions[0]->id, $questions[1]->id])
        ->and(Question::query()->where('lesson_id', $recordId)->orderBy('position')->pluck('position')->all())->toBe([1, 2, 3]);

    $questionId = $movedQuestion->id;
    $editor->call('addOption', $recordId, $questionId);
    $options = QuestionOption::query()->where('question_id', $questionId)->orderBy('position')->get();
    $movedOption = $options[2];
    $editor->call('moveOption', $recordId, $questionId, $movedOption->id, -1)
        ->call('moveOption', $recordId, $questionId, $movedOption->id, -1);

    expect(QuestionOption::query()->where('question_id', $questionId)->orderBy('position')->pluck('id')->all())
        ->toBe([$movedOption->id, $options[0]->id, $options[1]->id])
        ->and(QuestionOption::query()->where('question_id', $questionId)->orderBy('position')->pluck('position')->all())->toBe([1, 2, 3]);
})->with('course editor contexts');

it('reorders all three top-level records without changing sibling identity', function (string $contextName): void {
    [$fixture, $editor] = deepUnifiedEditor($contextName);
    $before = $editor->get('records');
    $movedId = $before[2]['id'];

    $editor->call('moveRecord', $movedId, -1)
        ->call('moveRecord', $movedId, -1)
        ->assertSet('records.0.id', $movedId);

    $after = $editor->get('records');
    expect(array_column($after, 'id'))->toBe([$movedId, $before[0]['id'], $before[1]['id']]);

    $positions = $fixture->context->name === 'company-course'
        ? Lesson::query()->whereIn('id', $fixture->recordIds)->orderBy('position')->pluck('position')->all()
        : CourseVersionModule::query()->whereIn('lesson_id', $fixture->recordIds)->orderBy('position')->pluck('position')->all();
    expect($positions)->toBe([1, 2, 3]);
})->with(['company course', 'shared course']);

it('rejects stale immediate structure and media operations without changing the post-conflict graph', function (string $contextName): void {
    [$fixture, $editor] = deepUnifiedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $record = Lesson::query()->findOrFail($recordId);
    $record->update(['title' => $fixture->token.' externally changed record']);
    $afterExternalWrite = deepGraphSnapshot($fixture);
    $questionCount = Question::query()->where('lesson_id', $recordId)->count();
    $videoCount = Video::query()->where('lesson_id', $recordId)->count();

    $editor->call('addQuestion', $recordId)
        ->assertSet('saveState', 'conflict')
        ->assertSet('errorKind', 'conflict')
        ->call('requestUpload', $recordId)
        ->assertSet('uploadInProgress', false)
        ->assertSet('operations', fn (array $operations): bool => collect($operations)->where('state', 'failed')->count() >= 2);

    expect(Question::query()->where('lesson_id', $recordId)->count())->toBe($questionCount)
        ->and(Video::query()->where('lesson_id', $recordId)->count())->toBe($videoCount)
        ->and(deepGraphSnapshot($fixture))->toBe($afterExternalWrite);
})->with('course editor contexts');

it('allows unrelated structure but blocks same-target media while an upload token is active', function (string $contextName): void {
    [$fixture, $editor] = deepUnifiedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $editor->call('requestUpload', $recordId)->assertSet('uploadInProgress', true);
    $uploads = $editor->get('activeUploads');
    $questionCount = Question::query()->where('lesson_id', $recordId)->count();
    $videoCount = Video::query()->where('lesson_id', $recordId)->count();

    $editor->call('addQuestion', $recordId)
        ->call('attachVideo', $recordId, $fixture->token.'-other-asset')
        ->assertSet('uploadInProgress', true)
        ->assertSet('activeUploads', $uploads);

    expect(Question::query()->where('lesson_id', $recordId)->count())->toBe($questionCount + 1)
        ->and(Video::query()->where('lesson_id', $recordId)->count())->toBe($videoCount)
        ->and(collect($editor->get('operations'))->where('state', 'failed')->count())->toBeGreaterThanOrEqual(1);
})->with('course editor contexts');
