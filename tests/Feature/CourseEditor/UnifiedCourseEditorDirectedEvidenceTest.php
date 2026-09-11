<?php

use App\Enums\Permission;
use App\Enums\VideoStatus;
use App\Models\ContentImage;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;

/** @return array{EditorFixture, Testable} */
function directedEditor(string $contextName): array
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

/** @return array<string, list<array<string, mixed>>> */
function directedPositionSnapshot(EditorFixture $fixture): array
{
    $recordIds = $fixture->recordIds;
    $questionIds = Question::query()->whereIn('lesson_id', $recordIds)->pluck('id');

    return [
        'lessons' => DB::table('lessons')->whereIn('id', $recordIds)->orderBy('id')->get(['id', 'position'])->map(fn ($row): array => (array) $row)->all(),
        'questions' => DB::table('questions')->whereIn('lesson_id', $recordIds)->orderBy('id')->get(['id', 'lesson_id', 'position'])->map(fn ($row): array => (array) $row)->all(),
        'options' => DB::table('question_options')->whereIn('question_id', $questionIds)->orderBy('id')->get(['id', 'question_id', 'position'])->map(fn ($row): array => (array) $row)->all(),
    ];
}

it('rejects a forged hydrated editor root and leaves both owners unchanged', function (string $contextName): void {
    [$fixture, $editor] = directedEditor($contextName);
    $foreign = EditorFixture::create($fixture->context);
    $before = $fixture->root->fresh()->getAttributes();
    $foreignBefore = $foreign->root->fresh()->getAttributes();

    expect(fn () => $editor->set('editorRootId', $foreign->root->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($fixture->root->fresh()->getAttributes())->toBe($before)
        ->and($foreign->root->fresh()->getAttributes())->toBe($foreignBefore);
})->with('course editor contexts');

it('makes every unconfirmed destructive compatibility entry point non-callable', function (string $contextName, string $method): void {
    [$fixture, $editor] = directedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $question = Question::query()->where('lesson_id', $recordId)->with('options')->firstOrFail();
    $option = $question->options->firstOrFail();
    $video = Video::factory()->create([
        'lesson_id' => $recordId,
        'company_id' => Lesson::query()->findOrFail($recordId)->company_id,
        'provider_asset_id' => $fixture->token.'-unconfirmed',
        'status' => VideoStatus::Ready,
        'is_current' => true,
    ]);
    $arguments = match ($method) {
        'removeRecord', 'removeLesson', 'removeCompositionRecord' => [$recordId],
        'removeQuestion' => [$recordId, $question->id],
        'removeOption' => [$recordId, $question->id, $option->id],
        'detachVideo' => [$recordId, $video->id],
    };
    $before = [
        'records' => Lesson::query()->whereIn('id', $fixture->recordIds)->count(),
        'questions' => Question::query()->whereIn('lesson_id', $fixture->recordIds)->count(),
        'options' => QuestionOption::query()->whereIn('question_id', Question::query()->whereIn('lesson_id', $fixture->recordIds)->pluck('id'))->count(),
        'videos' => Video::query()->where('lesson_id', $recordId)->count(),
    ];

    $editor->call($method, ...$arguments)->assertNotFound();

    expect([
        'records' => Lesson::query()->whereIn('id', $fixture->recordIds)->count(),
        'questions' => Question::query()->whereIn('lesson_id', $fixture->recordIds)->count(),
        'options' => QuestionOption::query()->whereIn('question_id', Question::query()->whereIn('lesson_id', $fixture->recordIds)->pluck('id'))->count(),
        'videos' => Video::query()->where('lesson_id', $recordId)->count(),
    ])->toBe($before);
})->with([
    'company remove record' => ['company course', 'removeRecord'],
    'company legacy remove lesson' => ['company course', 'removeLesson'],
    'company composition removal' => ['company course', 'removeCompositionRecord'],
    'question removal' => ['standalone shared module', 'removeQuestion'],
    'answer removal' => ['standalone shared module', 'removeOption'],
    'video removal' => ['company course', 'detachVideo'],
]);

it('binds destructive confirmation to the exact target and revisions and cancellation clears that authority', function (): void {
    [$fixture, $editor] = directedEditor('company course');
    $recordId = $fixture->recordIds[0];
    $question = Question::query()->where('lesson_id', $recordId)->with('options')->firstOrFail();
    $option = $question->options->firstOrFail();

    $editor->call('confirmAnswerDestruction', $recordId, $question->id, $option->id)
        ->assertSet('confirmingDestructive', true)
        ->assertSet('destructivePayload.option_id', $option->id)
        ->assertSet('destructiveExpectedRevision', fn (string $revision): bool => $revision !== '')
        ->assertSet('destructiveExpectedRecordRevision', fn (string $revision): bool => $revision !== '');

    expect(fn () => $editor->set('destructivePayload.option_id', $question->options->last()->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $editor->call('cancelDestructiveConfirmation', 'confirmingDestructive')
        ->assertSet('confirmingDestructive', false)
        ->assertSet('destructivePayload', [])
        ->assertSet('destructiveExpectedRevision', '');
    $editor->call('performConfirmedDestructive')->assertStatus(409);
    expect($option->fresh())->not->toBeNull();
});

it('a confirmed dirty answer removal drops only that answer and retains unrelated authored state through Save and reload', function (string $contextName): void {
    [$fixture, $editor] = directedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $question = Question::query()->where('lesson_id', $recordId)->with('options')->orderBy('position')->firstOrFail();
    $editor->call('addOption', $recordId, $question->id)->assertHasNoErrors();
    $question->refresh()->load('options');
    $options = $question->options->sortBy('position')->values();
    $kept = $options->first();
    $removed = $options->last();
    $stagedPrompt = $fixture->token.' retained prompt through removal';
    $stagedAnswer = $fixture->token.' retained answer through removal';

    $editor->set('records.0.questions.0.prompt', $stagedPrompt)
        ->set('records.0.questions.0.options.0.text', $stagedAnswer)
        ->call('confirmAnswerDestruction', $recordId, $question->id, $removed->id)
        ->call('performConfirmedDestructive')
        ->assertSet('editorDirty', true)
        ->assertSet('records.0.questions.0.prompt', $stagedPrompt)
        ->assertSet('records.0.questions.0.options.0.id', $kept->id)
        ->assertSet('records.0.questions.0.options.0.text', $stagedAnswer);

    expect(QuestionOption::query()->find($removed->id))->toBeNull()
        ->and($question->fresh()->prompt)->not->toBe($stagedPrompt)
        ->and($kept->fresh()->text)->not->toBe($stagedAnswer);

    $editor->call('saveDraft', false)->assertSet('saveState', 'saved');
    expect($question->fresh()->prompt)->toBe($stagedPrompt)
        ->and($kept->fresh()->text)->toBe($stagedAnswer)
        ->and(QuestionOption::query()->find($removed->id))->toBeNull();

    [, $reloaded] = directedEditorFromFixture($fixture);
    $reloaded->assertSet('records.0.questions.0.prompt', $stagedPrompt)
        ->assertSet('records.0.questions.0.options.0.id', $kept->id)
        ->assertSet('records.0.questions.0.options.0.text', $stagedAnswer);
})->with('course editor contexts');

it('a confirmed dirty question removal drops its exact subtree while sibling validation and authored values survive Save and reload', function (string $contextName): void {
    [$fixture, $editor] = directedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $questions = Question::query()->where('lesson_id', $recordId)->with('options')->orderBy('position')->get();
    $removed = $questions->first();
    $sibling = $questions->get(1);
    $removedOptionIds = $removed->options->pluck('id')->all();
    $stagedDescription = $fixture->token.' retained record description';
    $recoveredPrompt = $fixture->token.' recovered sibling prompt';
    $persistedDescription = Lesson::query()->findOrFail($recordId)->description;

    $editor->set('records.0.description', $stagedDescription)
        ->set('records.0.questions.1.prompt', '')
        ->call('saveDraft', false)
        ->assertSet('saveState', 'validation-error')
        ->assertHasErrors('records.0.questions.1.prompt')
        ->call('confirmQuestionDestruction', $recordId, $removed->id)
        ->call('performConfirmedDestructive')
        ->assertSet('editorDirty', true)
        ->assertSet('records.0.description', $stagedDescription)
        ->assertSet('records.0.questions.0.id', $sibling->id)
        ->assertSet('records.0.questions.0.prompt', '')
        ->assertHasErrors('records.0.questions.0.prompt');

    expect(Question::query()->find($removed->id))->toBeNull()
        ->and(QuestionOption::query()->whereIn('id', $removedOptionIds)->exists())->toBeFalse()
        ->and(Lesson::query()->findOrFail($recordId)->description)->toBe($persistedDescription)
        ->and($sibling->fresh()->prompt)->not->toBe($recoveredPrompt);

    $editor->set('records.0.questions.0.prompt', $recoveredPrompt)
        ->call('saveDraft', false)
        ->assertSet('saveState', 'saved');
    [, $reloaded] = directedEditorFromFixture($fixture);
    $reloaded->assertSet('records.0.description', $stagedDescription)
        ->assertSet('records.0.questions.0.id', $sibling->id)
        ->assertSet('records.0.questions.0.prompt', $recoveredPrompt);
    expect(Question::query()->find($removed->id))->toBeNull();
})->with('course editor contexts');

it('declines then confirms an exact dirty top-level subtree removal without resurrecting it after Save and reload', function (string $contextName): void {
    [$fixture, $editor] = directedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $siblingId = $fixture->recordIds[1];
    $staged = $fixture->token.' retained sibling after record removal';
    $beforeCount = count($editor->get('records'));

    $editor->set('records.1.description', $staged);
    if ($fixture->context->name === 'shared-course') {
        $record = $editor->get('records')[0];
        $editor->call('confirmModuleRemoval', $recordId, $record['composition_id'])
            ->call('cancelDestructiveConfirmation', 'confirmingModuleRemoval')
            ->assertSet('confirmingModuleRemoval', false)
            ->assertCount('records', $beforeCount)
            ->call('confirmModuleRemoval', $recordId, $record['composition_id'])
            ->set('moduleRemovalReason', 'Exact dirty composition removal')
            ->call('removeConfirmedModule');
    } else {
        $editor->call('confirmLessonRemoval', $recordId)
            ->call('cancelDestructiveConfirmation', 'confirmingDestructive')
            ->assertSet('confirmingDestructive', false)
            ->assertCount('records', $beforeCount)
            ->call('confirmLessonRemoval', $recordId)
            ->call('performConfirmedDestructive');
    }

    $editor->assertSet('editorDirty', true)
        ->assertCount('records', $beforeCount - 1)
        ->assertSet('records.0.id', $siblingId)
        ->assertSet('records.0.description', $staged)
        ->call('saveDraft', false)
        ->assertSet('saveState', 'saved');

    [, $reloaded] = directedEditorFromFixture($fixture);
    $reloaded->assertCount('records', $beforeCount - 1)
        ->assertSet('records.0.id', $siblingId)
        ->assertSet('records.0.description', $staged);
    if ($fixture->context->name === 'company-course') {
        expect(Lesson::query()->find($recordId))->toBeNull();
    } else {
        expect($fixture->root->versions()->where('status', 'draft')->sole()->moduleCompositions()->where('lesson_id', $recordId)->exists())->toBeFalse()
            ->and(ModuleVersion::query()->find($recordId))->not->toBeNull();
    }
})->with(['company course', 'shared course']);

it('a stale confirmed removal is a conflict with no deletion and all local values retained', function (string $contextName): void {
    [$fixture, $editor] = directedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $question = Question::query()->where('lesson_id', $recordId)->with('options')->firstOrFail();
    $option = $question->options->last();
    $staged = $fixture->token.' retained after stale confirmed remove';

    $editor->set('records.0.description', $staged)
        ->call('confirmAnswerDestruction', $recordId, $question->id, $option->id);
    Lesson::query()->findOrFail($recordId)->update(['title' => $fixture->token.' external revision']);

    $editor->call('performConfirmedDestructive')
        ->assertSet('saveState', 'conflict')
        ->assertSet('errorKind', 'conflict')
        ->assertSet('editorDirty', true)
        ->assertSet('records.0.description', $staged)
        ->assertSet('confirmingDestructive', true);

    expect(QuestionOption::query()->find($option->id))->not->toBeNull();
})->with('course editor contexts');

it('refuses to mint destructive confirmation authority from a revision newer than the mounted editor', function (string $contextName): void {
    [$fixture, $editor] = directedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $question = Question::query()->where('lesson_id', $recordId)->with('options')->firstOrFail();
    $option = $question->options->last();
    $staged = $fixture->token.' retained before confirmation conflict';
    $beforeOptionIds = $question->options()->orderBy('position')->pluck('id')->all();
    $heldRevision = $editor->get('revisions')['root'];

    $editor->set('records.0.description', $staged);
    Lesson::query()->findOrFail($recordId)->update(['title' => $fixture->token.' external before confirmation']);

    $editor->call('confirmAnswerDestruction', $recordId, $question->id, $option->id)
        ->assertSet('saveState', 'conflict')
        ->assertSet('errorKind', 'conflict')
        ->assertSet('editorDirty', true)
        ->assertSet('records.0.description', $staged)
        ->assertSet('confirmingDestructive', false)
        ->assertSet('destructiveExpectedRevision', '');

    expect($editor->get('revisions')['root'])->toBe($heldRevision)
        ->and($question->options()->orderBy('position')->pluck('id')->all())->toBe($beforeOptionIds);
})->with('course editor contexts');

it('dirty option and top-level reorder persist only order until the retained graph is explicitly saved', function (string $contextName): void {
    [$fixture, $editor] = directedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $question = Question::query()->where('lesson_id', $recordId)->with('options')->orderBy('position')->firstOrFail();
    $optionIds = $question->options->sortBy('position')->pluck('id')->all();
    $recordIds = $fixture->recordIds;
    $staged = $fixture->token.' retained through two reorders';
    $persistedBefore = Lesson::query()->findOrFail($recordId)->description;
    $initialRevisions = $editor->get('revisions');
    $initialRecordRevision = $initialRevisions['record:'.$recordId] ?? $initialRevisions['root'];

    $editor->set('records.0.description', $staged)
        ->call('moveOption', $recordId, $question->id, $optionIds[0], 1)
        ->assertSet('editorDirty', true);
    $afterOptionRevisions = $editor->get('revisions');
    $afterOptionRevision = $afterOptionRevisions['root'];
    $afterOptionRecordRevision = $afterOptionRevisions['record:'.$recordId] ?? $afterOptionRevision;
    $editor->call('moveRecord', $recordId, 1)
        ->assertSet('editorDirty', true)
        ->assertSet('records', fn (array $records): bool => collect($records)->firstWhere('id', $recordId)['description'] === $staged);
    $afterRecordRevision = $editor->get('revisions')['root'];

    expect($afterOptionRecordRevision)->not->toBe($initialRecordRevision)
        ->and($afterRecordRevision)->not->toBe($afterOptionRevision)
        ->and($question->options()->orderBy('position')->pluck('id')->all())->toBe([$optionIds[1], $optionIds[0]])
        ->and($fixture->context->name === 'company-course'
            ? Lesson::query()->whereIn('id', $recordIds)->orderBy('position')->pluck('id')->all()
            : $fixture->root->versions()->where('status', 'draft')->sole()->moduleCompositions()->orderBy('position')->pluck('lesson_id')->all())
        ->toBe([$recordIds[1], $recordIds[0], $recordIds[2]])
        ->and(Lesson::query()->findOrFail($recordId)->description)->toBe($persistedBefore);

    $editor->call('saveDraft', false)->assertSet('saveState', 'saved');
    [, $reloaded] = directedEditorFromFixture($fixture);
    $reloaded->assertSet('records', fn (array $records): bool => collect($records)->firstWhere('id', $recordId)['description'] === $staged);
    expect(Lesson::query()->findOrFail($recordId)->description)->toBe($staged)
        ->and($question->options()->orderBy('position')->pluck('id')->all())->toBe([$optionIds[1], $optionIds[0]]);
})->with(['company course', 'shared course']);

it('rejects a forged locked context before opening the company video library and never exposes foreign metadata or preview URLs', function (): void {
    [$fixture, $editor] = directedEditor('company course');
    $owner = 'company:'.$fixture->root->company_id;
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($owner) {
        if ($request->method() === 'GET') {
            return Http::response(['result' => [
                [
                    'uid' => 'owned-asset',
                    'meta' => ['name' => 'Owned library item', 'oceanix_owner' => $owner],
                    'status' => ['state' => 'ready'],
                    'playback' => ['hls' => 'https://owned.preview.example/manifest.m3u8'],
                ],
                [
                    'uid' => 'foreign-secret-asset',
                    'meta' => ['name' => 'Foreign secret title', 'oceanix_owner' => 'company:999999'],
                    'status' => ['state' => 'ready'],
                    'playback' => ['hls' => 'https://foreign.preview.example/secret.m3u8'],
                ],
            ]]);
        }

        return Http::response(['result' => ['token' => 'owned-preview-token']]);
    });

    expect(fn () => $editor->set('editorContextName', 'shared-course'))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $editor->call('openEditorVideoLibrary', 'records.0.content_markdown')
        ->assertSet('editorContextName', 'company-course')
        ->assertSet('videoLibraryOpen', true)
        ->assertSet('videoLibraryItems', fn (array $items): bool => count($items) === 1
            && $items[0]['asset_id'] === 'owned-asset'
            && $items[0]['title'] === 'Owned library item')
        ->assertDontSee('Foreign secret title')
        ->assertDontSee('foreign-secret-asset')
        ->assertDontSee('foreign.preview.example');
});

it('treats authored array reordering as content only and never writes structural positions', function (string $contextName): void {
    [$fixture, $editor] = directedEditor($contextName);
    $before = directedPositionSnapshot($fixture);
    $records = $editor->get('records');
    $records = array_reverse($records);
    $records[0]['questions'] = array_reverse($records[0]['questions']);
    $records[0]['questions'][0]['options'] = array_reverse($records[0]['questions'][0]['options']);

    $editor->set('records', $records)
        ->call('saveDraft', false);

    expect(directedPositionSnapshot($fixture))->toBe($before);
})->with('course editor contexts');

it('retains exact validation and conflict provenance when another authored field changes', function (string $contextName, string $failure): void {
    [$fixture, $editor] = directedEditor($contextName);
    $titlePath = $fixture->context->name === 'shared-module' ? 'records.0.title' : 'courseForm.title';

    if ($failure === 'validation-error') {
        $editor->set('records.0.questions.0.options.0.text', '')
            ->call('saveDraft', false)
            ->assertSet('saveState', 'validation-error')
            ->assertSet('errorKind', 'validation-error')
            ->assertSet('saveError', fn (?string $message): bool => filled($message));
    } else {
        $target = $fixture->context->name === 'shared-module'
            ? ModuleVersion::query()->findOrFail($fixture->recordIds[0])
            : $fixture->root;
        $target->update(['title' => $fixture->token.' remote provenance']);
        $editor->set($titlePath, $fixture->token.' local provenance')
            ->call('saveDraft', false)
            ->assertSet('saveState', 'conflict')
            ->assertSet('errorKind', 'conflict')
            ->assertSet('saveError', fn (?string $message): bool => filled($message));
    }

    $message = $editor->get('saveError');
    $editor->set('records.0.description', $fixture->token.' unrelated edit')
        ->assertSet('saveState', $failure)
        ->assertSet('errorKind', $failure)
        ->assertSet('saveError', $message)
        ->assertSet('editorDirty', true);
})->with('course editor contexts')->with(['validation-error', 'conflict']);

it('rolls back an unexpected failure at each distinct Save boundary and reports network provenance', function (string $contextName): void {
    [$fixture, $editor] = directedEditor($contextName);
    $titlePath = $fixture->context->name === 'shared-module' ? 'records.0.title' : 'courseForm.title';
    $target = $fixture->context->name === 'shared-module'
        ? ModuleVersion::query()->findOrFail($fixture->recordIds[0])
        : Course::query()->findOrFail($fixture->root->id);
    $before = $target->title;
    $changed = $fixture->token.' transaction must roll back';
    $table = $fixture->context->name === 'shared-module' ? 'lessons' : 'courses';
    $raised = false;

    DB::listen(function (QueryExecuted $query) use (&$raised, $table): void {
        if (! $raised && str_starts_with(strtolower(ltrim($query->sql)), 'update') && str_contains(strtolower($query->sql), $table)) {
            $raised = true;
            throw new RuntimeException('Directed unexpected persistence failure');
        }
    });

    $editor->set($titlePath, $changed)
        ->call('saveDraft', false)
        ->assertSet('saveState', 'network-error')
        ->assertSet('errorKind', 'network')
        ->assertSet('saveError', __("Changes weren't saved. Check your connection and try again."))
        ->assertSet($titlePath, $changed)
        ->assertSet('editorDirty', true);

    expect($raised)->toBeTrue()
        ->and($target->fresh()->title)->toBe($before);
})->with(['company course', 'standalone shared module']);

it('stages shared course code and single or multiple answer correctness until Save and reload', function (string $contextName, string $type, array $correctness): void {
    [$fixture, $editor] = directedEditor($contextName);
    $course = $fixture->context->name === 'shared-course' ? Course::query()->findOrFail($fixture->root->id) : null;
    $question = Question::query()->where('lesson_id', $fixture->recordIds[0])->with('options')->orderBy('position')->firstOrFail();
    $beforeCode = $course?->code;
    $beforeType = $question->type->value;
    $beforeCorrectness = $question->options->map(fn ($option): bool => (bool) $option->is_correct)->all();
    $code = $fixture->token.'-code';

    if ($course !== null) {
        $editor->set('courseForm.code', $code);
    }
    $editor->set('records.0.questions.0.type', $type)
        ->set('records.0.questions.0.options.0.is_correct', $correctness[0])
        ->set('records.0.questions.0.options.1.is_correct', $correctness[1])
        ->assertSet('editorDirty', true);

    expect($course?->fresh()->code)->toBe($beforeCode)
        ->and($question->fresh()->type->value)->toBe($beforeType)
        ->and($question->options()->orderBy('position')->get()->map(fn ($option): bool => (bool) $option->is_correct)->all())->toBe($beforeCorrectness);

    $editor->call('saveDraft', false)
        ->assertHasNoErrors()
        ->assertSet('saveState', 'saved');

    expect($course?->fresh()->code)->toBe($course === null ? null : strtoupper($code))
        ->and($question->fresh()->type->value)->toBe($type)
        ->and($question->options()->orderBy('position')->get()->map(fn ($option): bool => (bool) $option->is_correct)->all())->toBe($correctness);

    [, $reloaded] = directedEditorFromFixture($fixture);
    $reloaded->assertSet('records.0.questions.0.type', $type)
        ->assertSet('records.0.questions.0.options.0.is_correct', $correctness[0])
        ->assertSet('records.0.questions.0.options.1.is_correct', $correctness[1]);
    if ($course !== null) {
        $reloaded->assertSet('courseForm.code', strtoupper($code));
    }
})->with(['shared course', 'standalone shared module'])->with([
    'single choice' => ['single_choice', [false, true]],
    'multiple choice' => ['multiple_choice', [true, true]],
]);

/** @return array{EditorFixture, Testable} */
function directedEditorFromFixture(EditorFixture $fixture): array
{
    if ($fixture->user !== null) {
        Livewire::actingAs($fixture->user);
    }
    if ($fixture->session !== []) {
        test()->withSession($fixture->session);
    }

    return [$fixture, Livewire::test($fixture->context->component, $fixture->routeParameters())];
}

it('projects context-owned publication choices and readiness without shared-view branching', function (string $contextName, bool $assignmentMode, bool $restart): void {
    $fixture = EditorFixture::create(EditorContextCase::all()[$contextName]);
    if ($fixture->user !== null) {
        grantPermissions($fixture->user, [Permission::CoursesPublish], 'Directed publisher');
        Livewire::actingAs($fixture->user->fresh());
    }
    if ($fixture->session !== []) {
        test()->withSession($fixture->session);
    }

    $editor = Livewire::test($fixture->context->component, $fixture->routeParameters());
    $confirmation = $editor->get('publicationConfirmation');

    expect($confirmation)->toHaveKeys(['title', 'body', 'submit_label', 'problems_title', 'assignment_mode', 'restart_in_progress', 'restart_description'])
        ->and($confirmation['title'])->not->toBeEmpty()
        ->and($confirmation['submit_label'])->not->toBeEmpty()
        ->and($confirmation['assignment_mode'])->toBe($assignmentMode)
        ->and($confirmation['restart_in_progress'])->toBe($restart)
        ->and($editor->get('publicationProblems'))->toBeArray()
        ->and($editor->get('publicationImpact'))->toBeArray();
})->with([
    'company course' => ['company course', true, false],
    'shared course' => ['shared course', false, true],
    'standalone shared module' => ['standalone shared module', false, true],
]);

it('retains dirty authored state through a known image validation failure and recovers through explicit Save', function (string $contextName): void {
    [$fixture, $editor] = directedEditor($contextName);
    $record = Lesson::query()->findOrFail($fixture->recordIds[0]);
    $persisted = $record->description;
    $staged = $fixture->token.' retained through image validation failure';
    $image = ContentImage::query()->create([
        'company_id' => $fixture->context->name === 'company-course' ? $fixture->root->company_id : null,
        'is_shared' => $fixture->context->name !== 'company-course',
        'name' => $fixture->token.'-matrix.png',
        'disk' => 'public',
        'path' => 'content-images/'.$fixture->token.'-matrix.png',
        'mime_type' => 'image/png',
        'size_bytes' => 128,
    ]);

    $editor->set('records.0.description', $staged)
        ->call('openImageLibrary', 'records.0.content_markdown')
        ->call('uploadContentImage')
        ->assertHasErrors('contentImageUpload')
        ->assertSet('records.0.description', $staged)
        ->assertSet('editorDirty', true)
        ->assertSet('operations', fn (array $operations): bool => collect($operations)->contains(
            fn (array $operation): bool => $operation['state'] === 'failed' && $operation['action'] === 'upload-image',
        ))
        ->call('selectContentImage', $image->id)
        ->assertDispatched('oceanix:insert-image')
        ->assertSet('records.0.description', $staged)
        ->assertSet('editorDirty', true);
    expect($record->fresh()->description)->toBe($persisted);

    $editor->call('saveDraft', false)->assertSet('saveState', 'saved');
    [, $reloaded] = directedEditorFromFixture($fixture);
    $reloaded->assertSet('records.0.description', $staged);
    expect($record->fresh()->description)->toBe($staged);
})->with('course editor contexts');

it('attaches replaces and removes video while dirty without persisting authored values before explicit Save', function (string $contextName): void {
    [$fixture] = directedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $record = Lesson::query()->findOrFail($recordId);
    $previous = Video::factory()->create([
        'company_id' => $record->company_id,
        'lesson_id' => $recordId,
        'provider_asset_id' => $fixture->token.'-previous-matrix',
        'status' => VideoStatus::Ready,
        'is_current' => true,
        'replacement_generation' => 1,
    ]);
    $assetId = $fixture->token.'-attached-matrix';
    $owner = $record->company_id === null ? 'platform' : 'company:'.$record->company_id;
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => [
        'uid' => $assetId,
        'status' => ['state' => 'ready'],
        'duration' => 45,
        'requireSignedURLs' => true,
        'playback' => ['hls' => 'https://video.example/'.$assetId.'.m3u8'],
        'meta' => ['oceanix_owner' => $owner],
    ]])]);
    $editor = directedEditorFromFixture($fixture)[1];
    $persisted = $record->description;
    $staged = $fixture->token.' retained through video attach and removal';
    $beforeRevisions = $editor->get('revisions');
    $beforeRevision = $beforeRevisions['record:'.$recordId] ?? $beforeRevisions['root'];

    $editor->set('records.0.description', $staged)
        ->call('attachVideo', $recordId, $assetId)
        ->assertSet('records.0.description', $staged)
        ->assertSet('editorDirty', true);
    $attached = Video::query()->where('provider_asset_id', $assetId)->sole();
    $afterAttachRevisions = $editor->get('revisions');
    $afterAttachRevision = $afterAttachRevisions['record:'.$recordId] ?? $afterAttachRevisions['root'];
    expect($afterAttachRevision)->not->toBe($beforeRevision)
        ->and($previous->fresh()->is_current)->toBeFalse()
        ->and($attached->is_current)->toBeTrue()
        ->and($record->fresh()->description)->toBe($persisted);

    $editor->call('confirmVideoDestruction', $recordId, $attached->id)
        ->call('performConfirmedDestructive')
        ->assertSet('records.0.description', $staged)
        ->assertSet('editorDirty', true);
    expect($attached->fresh()->is_current)->toBeFalse()
        ->and($record->fresh()->description)->toBe($persisted);

    $editor->call('saveDraft', false)->assertSet('saveState', 'saved');
    [, $reloaded] = directedEditorFromFixture($fixture);
    $reloaded->assertSet('records.0.description', $staged);
    expect($record->fresh()->description)->toBe($staged)
        ->and($attached->fresh()->is_current)->toBeFalse();
})->with('course editor contexts');

it('keeps a failed upload token retryable and switches the current video only when retry reaches Ready', function (string $contextName): void {
    [$fixture, $editor] = directedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $record = ModuleVersion::query()->find($recordId) ?? Lesson::query()->findOrFail($recordId);
    $previous = Video::factory()->create([
        'company_id' => $record->company_id,
        'lesson_id' => $recordId,
        'status' => VideoStatus::Ready,
        'is_current' => true,
        'replacement_generation' => 1,
    ]);
    $editor = directedEditorFromFixture($fixture)[1];
    $stagedDescription = $fixture->token.' retained through provider failure';
    $persistedDescription = $record->description;
    $editor->set('records.0.description', $stagedDescription)->assertSet('editorDirty', true);
    $owner = $record->company_id === null ? 'platform' : 'company:'.$record->company_id;
    $assetId = $fixture->token.'-retry-asset';
    $statusAttempts = 0;
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(function ($request) use (&$statusAttempts, $assetId, $owner) {
        if ($request->method() === 'POST') {
            return Http::response(['success' => true, 'result' => [
                'uid' => $assetId,
                'uploadURL' => 'https://upload.example/'.$assetId,
            ]]);
        }
        if (++$statusAttempts === 1) {
            return Http::failedConnection();
        }

        return Http::response(['success' => true, 'result' => [
            'uid' => $assetId,
            'status' => ['state' => 'ready'],
            'duration' => 91,
            'requireSignedURLs' => true,
            'playback' => ['hls' => 'https://video.example/retry.m3u8'],
            'meta' => ['oceanix_owner' => $owner],
        ]]);
    });

    $editor->call('requestUpload', $recordId)->assertSet('uploadInProgress', true);
    $token = array_key_first($editor->get('activeUploads'));
    $candidateId = $editor->get("activeUploads.{$token}.video_id");

    $editor->call('uploadCompleted', $recordId, $token)
        ->assertSet('uploadInProgress', false)
        ->assertSet("activeUploads.{$token}.state", 'failed')
        ->assertSet("activeUploads.{$token}.candidate_failed", true)
        ->assertSet("operations.upload:{$recordId}.retry_token", $token)
        ->assertSet('records.0.description', $stagedDescription)
        ->assertSet('editorDirty', true)
        ->assertSee('data-editor-media-action="retry-upload"', escape: false);

    expect($previous->fresh()->is_current)->toBeTrue()
        ->and(Video::query()->findOrFail($candidateId)->status)->toBe(VideoStatus::Failed)
        ->and(Video::query()->findOrFail($candidateId)->is_current)->toBeFalse()
        ->and($record->fresh()->description)->toBe($persistedDescription);

    $editor->call('retryUpload', $recordId, $token)
        ->assertSet('uploadInProgress', false)
        ->assertSet('activeUploads', [])
        ->assertSet("operations.upload:{$recordId}.state", 'succeeded')
        ->assertSet('records.0.description', $stagedDescription)
        ->assertSet('editorDirty', true);

    expect($previous->fresh()->is_current)->toBeFalse()
        ->and(Video::query()->findOrFail($candidateId)->status)->toBe(VideoStatus::Ready)
        ->and(Video::query()->findOrFail($candidateId)->is_current)->toBeTrue()
        ->and($record->fresh()->description)->toBe($persistedDescription);

    $editor->call('saveDraft', false)->assertSet('saveState', 'saved');
    [, $reloaded] = directedEditorFromFixture($fixture);
    $reloaded->assertSet('records.0.description', $stagedDescription);
    expect($record->fresh()->description)->toBe($stagedDescription);
})->with('course editor contexts');
