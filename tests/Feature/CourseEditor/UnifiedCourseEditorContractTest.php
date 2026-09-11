<?php

use App\Enums\VideoStatus;
use App\Models\Account;
use App\Models\ContentImage;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\Video;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;

/** @return array{EditorFixture, Testable} */
function unifiedEditor(string $contextName, bool $withAttachedVideo = false): array
{
    $fixture = EditorFixture::create(EditorContextCase::all()[$contextName]);

    if ($withAttachedVideo) {
        attachVideoForUnifiedContractFixture($fixture);
    }

    if ($fixture->user !== null) {
        Livewire::actingAs($fixture->user);
    }

    if ($fixture->session !== []) {
        test()->withSession($fixture->session);
    }

    return [$fixture, Livewire::test($fixture->context->component, $fixture->routeParameters())];
}

function attachVideoForUnifiedContractFixture(EditorFixture $fixture): Video
{
    $record = $fixture->context->name === 'company-course'
        ? Lesson::query()->findOrFail($fixture->recordIds[0])
        : ModuleVersion::query()->findOrFail($fixture->recordIds[0]);

    return Video::factory()->create([
        'lesson_id' => $record->id,
        'company_id' => $record->company_id,
        'provider_asset_id' => $fixture->token.'-attached-video',
        'status' => VideoStatus::Ready,
        'is_current' => true,
        'replacement_generation' => 1,
        'metadata' => ['hls' => 'https://video.example/manifest.m3u8', 'width' => 1280, 'height' => 720],
    ]);
}

/** @return array{string, Model} */
function unifiedTitleTarget(EditorFixture $fixture): array
{
    if ($fixture->context->name === 'shared-module') {
        return ['records.0.title', ModuleVersion::query()->findOrFail($fixture->recordIds[0])];
    }

    return ['courseForm.title', Course::query()->findOrFail($fixture->root->id)];
}

it('renders the same semantic contract in every editor context', function (string $contextName): void {
    [$fixture, $editor] = unifiedEditor($contextName);

    $editor->assertSee('data-editor-root', escape: false)
        ->assertSee('data-editor-context="'.$fixture->context->name.'"', escape: false)
        ->assertSee('data-editor-save', escape: false)
        ->assertSee('data-editor-status', escape: false)
        ->assertSee('data-editor-record', escape: false)
        ->assertSee('data-editor-field', escape: false);
})->with('course editor contexts');

it('keeps video access in the rich-content toolbar without a standalone record footer', function (string $contextName): void {
    [, $editor] = unifiedEditor($contextName, withAttachedVideo: true);

    $editor->assertDontSeeText('No video attached')
        ->assertDontSeeText('Choose or upload video')
        ->assertDontSeeText('Replace video')
        ->assertDontSeeText('Remove video')
        ->assertSee('data-editor-media-action="open-library"', escape: false)
        ->assertSee('data-editor-action-detail="open-video-library"', escape: false)
        ->assertSee("x-on:click=\"\$dispatch('oceanix-open-video-library'", escape: false)
        ->assertSee('wire:model.self="videoLibraryOpen"', escape: false)
        ->call('openEditorVideoLibrary', 'records.0.content_markdown')
        ->assertSet('videoLibraryOpen', true)
        ->assertSee('data-editor-upload-picker', escape: false)
        ->assertSee('wire:click="searchVideoLibrary"', escape: false);
})->with('course editor contexts');

it('renders the approved composition search and image hooks with associated disabled and upload guidance', function (): void {
    [$fixture, $editor] = unifiedEditor('shared course');
    $image = ContentImage::query()->create([
        'company_id' => null,
        'is_shared' => true,
        'name' => 'semantic-image.png',
        'disk' => 'public',
        'path' => 'content-images/semantic-image.png',
        'mime_type' => 'image/png',
        'size_bytes' => 128,
    ]);

    $editor->assertSee('data-editor-structure-action="add"', escape: false)
        ->assertSee('data-editor-action-detail="composition-create"', escape: false)
        ->assertSee('data-editor-action-detail="composition-attach"', escape: false)
        ->assertSee('data-editor-action-detail="search-retry"', escape: false)
        ->assertSee('aria-describedby="editor-add-module-guidance"', escape: false)
        ->assertSee('id="editor-add-module-guidance"', escape: false)
        ->assertSee('aria-describedby="editor-publish-guidance"', escape: false)
        ->assertSee('id="editor-publish-guidance"', escape: false)
        ->assertDontSeeText(__('Save your authored changes before adding a module.'))
        ->assertSeeText(__('Wait for active uploads to finish before publishing.'))
        ->call('openImageLibrary', 'records.0.content_markdown')
        ->assertSee('data-editor-media-action="upload"', escape: false)
        ->assertSee('data-editor-action-detail="image-upload"', escape: false)
        ->assertSee('data-editor-media-action="attach"', escape: false)
        ->assertSee('data-editor-action-detail="image-select"', escape: false)
        ->assertSee('wire:click="selectContentImage('.$image->id.')"', escape: false)
        ->call('uploadContentImage')
        ->assertHasErrors('contentImageUpload')
        ->assertSee('id="editor-content-image-error"', escape: false)
        ->assertSee('role="alert"', escape: false);
    expect($editor->html())->toMatch('/<input[^>]+wire:model="contentImageUpload"[^>]+aria-describedby="[^"]*editor-content-image-help[^"]*editor-content-image-error[^"]*"/');
});

it('uses only the frozen operation hook vocabulary and keeps finer identity separate', function (string $contextName): void {
    [, $editor] = unifiedEditor($contextName);
    $html = $editor->html();
    preg_match_all('/data-editor-structure-action="([^"]+)"/', $html, $structureMatches);
    preg_match_all('/data-editor-media-action="([^"]+)"/', $html, $mediaMatches);

    expect(array_values(array_unique($structureMatches[1] ?? [])))
        ->each->toBeIn(['add', 'remove', 'move-up', 'move-down', 'reorder', 'change-composition'])
        ->and(array_values(array_unique($mediaMatches[1] ?? [])))
        ->each->toBeIn(['upload', 'retry-upload', 'open-library', 'attach', 'replace', 'remove'])
        ->and($html)->toContain('data-editor-action-detail=')
        ->and($html)->toContain('data-editor-operational-guidance')
        ->and($html)->toContain('x-bind:data-guidance-severity="operationalGuidanceSeverity"')
        ->and($html)->toContain("x-bind:role=\"operationalGuidanceSeverity === 'danger' ? 'alert' : 'status'\"")
        ->and($html)->toContain("x-bind:aria-live=\"operationalGuidanceSeverity === 'danger' ? 'assertive' : 'polite'\"")
        ->and($html)->toContain('data-editor-guidance-error')
        ->and($html)->toContain('border-red-200 bg-red-50 text-red-900')
        ->and($html)->toContain('data-editor-hydration-state="loading"');
})->with('course editor contexts');

it('renders either an exact retry action or explicit unavailable guidance for every failed non-upload operation', function (): void {
    [, $editor] = unifiedEditor('company course');
    $editor->set('operations', [
        'structure:add-question:7' => [
            'state' => 'failed',
            'kind' => 'structure',
            'action' => 'add-question',
            'target_key' => 'lesson:7',
            'target_label' => 'Safety lesson',
            'error' => 'Temporary request failure',
            'retry_available' => true,
            'retry_guidance' => 'Retry this exact action for Safety lesson.',
        ],
        'media:remove:8' => [
            'state' => 'failed',
            'kind' => 'media',
            'action' => 'remove',
            'target_key' => 'lesson:8',
            'target_label' => 'Locked lesson',
            'error' => 'The draft changed',
            'retry_available' => false,
            'retry_guidance' => 'Resolve the editor state, then use the original action again.',
        ],
    ])
        ->assertSee('data-operation-retry-available="true"', escape: false)
        ->assertSee('wire:click="retryOperation(\'structure:add-question:7\')"', escape: false)
        ->assertSee('data-editor-structure-action="add"', escape: false)
        ->assertSee('data-editor-action-detail="retry-add-question"', escape: false)
        ->assertSee('data-editor-target-key="lesson:7"', escape: false)
        ->assertSeeText('Retry this exact action for Safety lesson.')
        ->assertSee('data-operation-retry-available="false"', escape: false)
        ->assertSee('data-operation-retry-unavailable', escape: false)
        ->assertSeeText('Resolve the editor state, then use the original action again.');
});

it('keeps authored changes staged until the atomic Save succeeds', function (string $contextName): void {
    [$fixture, $editor] = unifiedEditor($contextName);
    [$path, $model] = unifiedTitleTarget($fixture);
    $before = $model->title;
    $changed = $fixture->token.' Explicit Save';

    $editor->set($path, $changed)
        ->assertSet('editorDirty', true)
        ->assertSet('saveState', 'dirty');

    expect($model->fresh()->title)->toBe($before);

    $editor->call('saveDraft', false)
        ->assertHasNoErrors()
        ->assertSet('editorDirty', false)
        ->assertSet('saveState', 'saved');

    expect($model->fresh()->title)->toBe($changed);
})->with('course editor contexts');

it('does not create a revision or timestamp write when Save has no changes', function (string $contextName): void {
    [$fixture, $editor] = unifiedEditor($contextName);
    [, $model] = unifiedTitleTarget($fixture);
    $beforeRevision = $editor->get('revisions');
    $beforeUpdatedAt = $model->updated_at?->format('Y-m-d H:i:s.u');

    $editor->call('saveDraft', false)
        ->assertHasNoErrors()
        ->assertSet('editorDirty', false)
        ->assertSet('saveState', 'clean')
        ->assertSet('revisions', $beforeRevision);

    expect($model->fresh()->updated_at?->format('Y-m-d H:i:s.u'))->toBe($beforeUpdatedAt);
})->with('course editor contexts');

it('rolls back the complete authored graph when a later value is invalid', function (string $contextName): void {
    [$fixture, $editor] = unifiedEditor($contextName);
    [$path, $model] = unifiedTitleTarget($fixture);
    $question = Question::query()->where('lesson_id', $fixture->recordIds[0])->with('options')->firstOrFail();
    $beforeTitle = $model->title;
    $beforeAnswer = $question->options->last()->text;
    $changed = $fixture->token.' Must roll back';

    $editor->set($path, $changed)
        ->set('records.0.questions.0.options.1.text', '')
        ->call('saveDraft', false)
        ->assertSet('editorDirty', true)
        ->assertSet('saveState', 'validation-error')
        ->assertSet('records.0.questions.0.options.1.text', '');

    expect($model->fresh()->title)->toBe($beforeTitle)
        ->and($question->options()->findOrFail($question->options->last()->id)->text)->toBe($beforeAnswer);
})->with('course editor contexts');

it('retains mapped validation errors and authored values through an immediate addition', function (string $contextName): void {
    [$fixture, $editor] = unifiedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $question = Question::query()->where('lesson_id', $recordId)->orderBy('position')->firstOrFail();
    $invalidPath = 'records.0.questions.0.options.1.text';
    $before = $question->options()->count();

    $editor->set($invalidPath, '   ')
        ->call('saveDraft', false)
        ->assertSet('saveState', 'validation-error')
        ->assertHasErrors($invalidPath)
        ->call('addOption', $recordId, $question->id)
        ->assertSet('saveState', 'validation-error')
        ->assertSet('editorDirty', true)
        ->assertSet($invalidPath, '   ')
        ->assertHasErrors($invalidPath);

    expect($question->options()->count())->toBe($before + 1);
})->with('course editor contexts');

it('retains a staged question by stable identity through reorder and saves with the refreshed revision', function (string $contextName): void {
    [$fixture, $editor] = unifiedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $questions = Question::query()->where('lesson_id', $recordId)->orderBy('position')->get();
    $moved = $questions[1];
    $staged = $fixture->token.' staged before reorder';

    $editor->set('records.0.questions.1.prompt', $staged)
        ->call('moveQuestion', $recordId, $moved->id, -1)
        ->assertSet('records.0.questions.0.id', $moved->id)
        ->assertSet('records.0.questions.0.prompt', $staged)
        ->assertSet('editorDirty', true)
        ->call('saveDraft', false)
        ->assertSet('saveState', 'saved');

    expect($moved->fresh()->position)->toBe(1)
        ->and($moved->fresh()->prompt)->toBe($staged);
})->with('course editor contexts');

it('rejects a stale whole-graph Save while retaining local values', function (string $contextName): void {
    [$fixture, $editor] = unifiedEditor($contextName);
    [$path, $model] = unifiedTitleTarget($fixture);
    $local = $fixture->token.' Local pending title';
    $remote = $fixture->token.' Concurrent title';

    $editor->set($path, $local);
    $model->update(['title' => $remote]);

    $editor->call('saveDraft', false)
        ->assertSet($path, $local)
        ->assertSet('editorDirty', true)
        ->assertSet('saveState', 'conflict');

    expect($model->fresh()->title)->toBe($remote);
})->with('course editor contexts');

it('refuses the complete Save after edit permission is revoked', function (string $contextName): void {
    [$fixture, $editor] = unifiedEditor($contextName);
    [$path, $model] = unifiedTitleTarget($fixture);
    $before = $model->title;
    $pending = $fixture->token.' Revoked pending title';

    $editor->set($path, $pending)->assertSet('editorDirty', true);

    if ($fixture->user !== null) {
        $fixture->user->roles()->detach();
    } else {
        Account::query()->findOrFail($fixture->session['platform_account_id'])->update(['is_platform_admin' => false]);
    }

    $editor->call('saveDraft', false)
        ->assertSet($path, $pending)
        ->assertSet('editorDirty', true)
        ->assertSet('saveState', 'permission-lost');

    expect($model->fresh()->title)->toBe($before);
})->with('course editor contexts');

it('persists a structural operation immediately with a stable database id', function (string $contextName): void {
    [$fixture, $editor] = unifiedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $before = Question::query()->where('lesson_id', $recordId)->count();

    $editor->call('addQuestion', $recordId)
        ->assertSet('editorDirty', false)
        ->assertSet('saveState', 'clean')
        ->assertCount('records.0.questions', $before + 1);

    $created = Question::query()->where('lesson_id', $recordId)->orderByDesc('position')->firstOrFail();
    expect($created->id)->toBeGreaterThan(0)
        ->and($created->options()->count())->toBe(2)
        ->and(data_get($editor->get('records'), '0.questions.'.$before.'.id'))->not->toBeNull();
})->with('course editor contexts');

it('adds another answer while a newly added answer value remains staged and unpersisted', function (string $contextName): void {
    [$fixture, $editor] = unifiedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $question = Question::query()->where('lesson_id', $recordId)->orderBy('position')->firstOrFail();
    $initialCount = $question->options()->count();
    $initialOptionIds = $question->options()->pluck('id')->all();
    $initialPrompt = $question->prompt;
    $initialExistingText = $question->options()->orderBy('position')->firstOrFail()->text;
    $revisionKey = $fixture->context->name === 'shared-course' ? 'record:'.$recordId : 'root';
    $revisionBefore = $editor->get('revisions.'.$revisionKey);

    $editor->call('addOption', $recordId, $question->id)->assertHasNoErrors();

    $firstAdded = $question->options()->whereNotIn('id', $initialOptionIds)->firstOrFail();
    $persistedBefore = $firstAdded->text;
    $records = $editor->get('records');
    $optionIndex = collect($records[0]['questions'][0]['options'])->search(
        fn (array $option): bool => (int) $option['id'] === $firstAdded->id,
    );
    expect($optionIndex)->not->toBeFalse();

    $path = "records.0.questions.0.options.{$optionIndex}.text";
    $stagedText = $fixture->token.' staged answer';
    $stagedPrompt = $fixture->token.' staged prompt';
    $stagedExistingText = $fixture->token.' staged existing answer';
    $editor->set($path, $stagedText)
        ->set('records.0.questions.0.prompt', $stagedPrompt)
        ->set('records.0.questions.0.options.0.text', $stagedExistingText)
        ->assertSet('editorDirty', true)
        ->assertSet($path, $stagedText)
        ->call('addOption', $recordId, $question->id)
        ->assertHasNoErrors()
        ->assertSet($path, $stagedText)
        ->assertSet('records.0.questions.0.prompt', $stagedPrompt)
        ->assertSet('records.0.questions.0.options.0.text', $stagedExistingText);

    $optionsAfterActions = collect($editor->get('records.0.questions.0.options'));
    $secondAdded = $question->options()->whereNotIn('id', [...$initialOptionIds, $firstAdded->id])->firstOrFail();
    $secondIndex = $optionsAfterActions->search(fn (array $option): bool => (int) $option['id'] === $secondAdded->id);
    $secondPath = "records.0.questions.0.options.{$secondIndex}.text";
    $secondStagedText = $fixture->token.' second staged answer';
    $editor->set($secondPath, $secondStagedText)->assertSet($secondPath, $secondStagedText);
    $revisionAfterActions = $editor->get('revisions.'.$revisionKey);

    expect($question->options()->count())->toBe($initialCount + 2)
        ->and($firstAdded->fresh()->text)->toBe($persistedBefore)
        ->and($question->fresh()->prompt)->toBe($initialPrompt)
        ->and($question->options()->orderBy('position')->firstOrFail()->text)->toBe($initialExistingText)
        ->and($revisionAfterActions)->not->toBe($revisionBefore)
        ->and(collect($editor->get('records')[0]['questions'][0]['options'])->pluck('id'))
        ->toContain($firstAdded->id);

    $editor->call('saveDraft', false)->assertSet('saveState', 'saved');
    expect($firstAdded->fresh()->text)->toBe($stagedText)
        ->and($secondAdded->fresh()->text)->toBe($secondStagedText)
        ->and($question->fresh()->prompt)->toBe($stagedPrompt)
        ->and($question->options()->orderBy('position')->firstOrFail()->text)->toBe($stagedExistingText);

    [, $reloaded] = unifiedEditorFromFixture($fixture);
    $reloadedOptions = collect($reloaded->get('records.0.questions.0.options'));
    expect($reloaded->get('records.0.questions.0.prompt'))->toBe($stagedPrompt)
        ->and($reloadedOptions->firstWhere('id', $firstAdded->id)['text'])->toBe($stagedText)
        ->and($reloadedOptions->firstWhere('id', $secondAdded->id)['text'])->toBe($secondStagedText)
        ->and($reloadedOptions->firstWhere('id', $initialOptionIds[0])['text'])->toBe($stagedExistingText);
})->with('course editor contexts');

/** @return array{EditorFixture, Testable} */
function unifiedEditorFromFixture(EditorFixture $fixture): array
{
    if ($fixture->user !== null) {
        Livewire::actingAs($fixture->user);
    }
    if ($fixture->session !== []) {
        test()->withSession($fixture->session);
    }

    return [$fixture, Livewire::test($fixture->context->component, $fixture->routeParameters())];
}

it('attaches a composition record while authored values are dirty without persisting those values', function (): void {
    [$fixture, $editor] = unifiedEditor('shared course');
    $sourceRoot = Module::factory()->shared()->create(['status' => 'active']);
    $source = ModuleVersion::factory()->published()->create(['module_id' => $sourceRoot]);
    $beforeTitle = $fixture->root->fresh()->title;
    $beforeCount = CourseVersionModule::query()->where('course_version_id', $fixture->root->versions()->where('status', 'draft')->sole()->id)->count();
    $staged = $fixture->token.' staged through composition attach';
    $beforeRevision = $editor->get('revisions')['root'];

    $editor->set('courseForm.title', $staged)
        ->set('records.0.questions.0.options.0.text', '')
        ->call('saveDraft', false)
        ->assertSet('saveState', 'validation-error')
        ->call('searchAvailableModules')
        ->set('selectedModuleId', $source->id)
        ->call('addSelectedModule')
        ->assertSet('editorDirty', true)
        ->assertSet('courseForm.title', $staged)
        ->assertSet('selectedModuleId', null)
        ->assertSet('saveState', 'validation-error')
        ->assertHasErrors('records.0.questions.0.options.0.text');

    expect($fixture->root->fresh()->title)->toBe($beforeTitle)
        ->and(CourseVersionModule::query()->where('course_version_id', $fixture->root->versions()->where('status', 'draft')->sole()->id)->count())->toBe($beforeCount + 1)
        ->and($editor->get('revisions')['root'])->not->toBe($beforeRevision);

    $editor->set('records.0.questions.0.options.0.text', $fixture->token.' recovered answer')
        ->call('saveDraft', false)
        ->assertSet('saveState', 'saved');
    [, $reloaded] = unifiedEditorFromFixture($fixture);
    $reloaded->assertSet('courseForm.title', $staged)
        ->assertCount('records', $beforeCount + 1);
    expect($fixture->root->fresh()->title)->toBe($staged)
        ->and(CourseVersionModule::query()->where('course_version_id', $fixture->root->versions()->where('status', 'draft')->sole()->id)->count())->toBe($beforeCount + 1);
});

it('changes company composition while root fields and validation remain staged until explicit Save', function (): void {
    $fixture = EditorFixture::create(EditorContextCase::companyCourse());
    $course = Course::query()->findOrFail($fixture->root->id);
    $version = CourseVersion::query()->where('course_id', $course->id)->where('status', 'draft')->sole();
    $version->moduleCompositions()->delete();
    $version->lessons()->each(fn (Lesson $lesson) => $lesson->delete());

    $moduleRoot = Module::factory()->create([
        'company_id' => $course->company_id,
        'status' => 'active',
        'title' => $fixture->token.' eligible company module',
    ]);
    $selected = ModuleVersion::factory()->published()->create([
        'module_id' => $moduleRoot->id,
        'title' => $moduleRoot->title,
    ]);
    $moduleRoot->update(['current_published_version_id' => $selected->id]);

    [, $editor] = unifiedEditorFromFixture($fixture);
    $courseBefore = $course->only(['title', 'description']);
    $versionBefore = $version->only(['title', 'description']);
    $revisionBefore = $editor->get('revisions')['root'];
    $stagedDescription = $fixture->token.' staged company description';
    $stagedEmployeeDescription = $fixture->token.' staged employee description';

    $editor->set('courseForm.title', '')
        ->set('courseForm.description', $stagedDescription)
        ->set('versionForm.description', $stagedEmployeeDescription)
        ->call('saveDraft', false)
        ->assertSet('saveState', 'validation-error')
        ->assertSet('editorDirty', true)
        ->assertHasErrors('courseForm.title');
    $dirtyGeneration = $editor->get('localGeneration');

    $editor->call('searchAvailableModules')
        ->set('selectedModuleId', $selected->id)
        ->call('addSelectedModule')
        ->assertSet('selectedModuleId', null)
        ->assertSet('courseForm.title', '')
        ->assertSet('courseForm.description', $stagedDescription)
        ->assertSet('versionForm.description', $stagedEmployeeDescription)
        ->assertSet('localGeneration', $dirtyGeneration)
        ->assertSet('editorDirty', true)
        ->assertSet('saveState', 'validation-error')
        ->assertHasErrors('courseForm.title')
        ->assertCount('preservedRecords', 1);

    expect($course->fresh()->only(['title', 'description']))->toBe($courseBefore)
        ->and($version->fresh()->only(['title', 'description']))->toBe($versionBefore)
        ->and($version->moduleCompositions()->orderBy('position')->pluck('lesson_id')->all())->toBe([$selected->id])
        ->and($editor->get('revisions')['root'])->not->toBe($revisionBefore);

    $savedTitle = $fixture->token.' saved company title';
    $editor->set('courseForm.title', $savedTitle)
        ->call('saveDraft', false)
        ->assertSet('saveState', 'saved');

    [, $reloaded] = unifiedEditorFromFixture($fixture);
    $reloaded->assertSet('courseForm.title', $savedTitle)
        ->assertSet('courseForm.description', $stagedDescription)
        ->assertSet('versionForm.description', $stagedEmployeeDescription)
        ->assertCount('preservedRecords', 1);
    expect($course->fresh()->title)->toBe($savedTitle)
        ->and($course->fresh()->description)->toBe($stagedDescription)
        ->and($version->fresh()->description)->toBe($stagedEmployeeDescription)
        ->and($version->moduleCompositions()->orderBy('position')->pluck('lesson_id')->all())->toBe([$selected->id]);
});

it('allows immediate structure while authored values remain dirty and unpersisted', function (string $contextName): void {
    [$fixture, $editor] = unifiedEditor($contextName);
    [$path] = unifiedTitleTarget($fixture);
    $recordId = $fixture->recordIds[0];
    $before = Question::query()->where('lesson_id', $recordId)->count();

    $editor->set($path, $fixture->token.' Pending')
        ->assertSet('editorDirty', true);

    $editor->call('addQuestion', $recordId)
        ->assertSet('editorDirty', true)
        ->assertSet($path, $fixture->token.' Pending')
        ->assertCount('records.0.questions', $before + 1);
    expect(Question::query()->where('lesson_id', $recordId)->count())->toBe($before + 1);
})->with('course editor contexts');

it('allows an upload allocation while unrelated authored values remain dirty and unpersisted', function (string $contextName): void {
    [$fixture, $editor] = unifiedEditor($contextName);
    [$path] = unifiedTitleTarget($fixture);
    $recordId = $fixture->recordIds[0];
    $before = Video::query()->where('lesson_id', $recordId)->count();

    $editor->set($path, $fixture->token.' Pending before upload')
        ->assertSet('editorDirty', true)
        ->call('requestUpload', $recordId)
        ->assertSet('editorDirty', true)
        ->assertSet($path, $fixture->token.' Pending before upload')
        ->assertSet('uploadInProgress', true);

    expect(Video::query()->where('lesson_id', $recordId)->count())->toBe($before + 1);
})->with('course editor contexts');

it('retries a failed client transfer through a fresh allocation while preserving its logical token and prior media', function (string $contextName): void {
    [$fixture] = unifiedEditor($contextName);
    $recordId = $fixture->recordIds[0];
    $record = $fixture->context->name === 'company-course'
        ? Lesson::query()->findOrFail($recordId)
        : ModuleVersion::query()->findOrFail($recordId);
    $previous = Video::factory()->create([
        'lesson_id' => $recordId,
        'company_id' => $record->company_id,
        'provider_asset_id' => $fixture->token.'-previous',
        'status' => VideoStatus::Ready,
        'is_current' => true,
        'replacement_generation' => 1,
    ]);

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/*/stream/direct_upload' => Http::sequence()
            ->push(['success' => true, 'result' => [
                'uid' => $fixture->token.'-failed-transfer',
                'uploadURL' => 'https://upload.example/first-transfer',
            ]])
            ->push(['success' => true, 'result' => [
                'uid' => $fixture->token.'-retried-transfer',
                'uploadURL' => 'https://upload.example/second-transfer',
            ]]),
        'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []]),
    ]);

    $editor = Livewire::test($fixture->context->component, $fixture->routeParameters());
    $before = Video::query()->where('lesson_id', $recordId)->count();

    $editor->call('requestUpload', $recordId)
        ->assertSet('editorDirty', false)
        ->assertSet('uploadInProgress', true);

    $uploads = $editor->get('activeUploads');
    $token = array_key_first($uploads);
    $firstCandidateId = $uploads[$token]['video_id'];
    expect($token)->not->toBeNull()
        ->and($uploads[$token]['record_id'])->toBe($recordId)
        ->and($uploads[$token]['asset_id'])->toBe($fixture->token.'-failed-transfer')
        ->and(Video::query()->where('lesson_id', $recordId)->count())->toBe($before + 1)
        ->and($previous->fresh()->is_current)->toBeTrue()
        ->and(Video::query()->findOrFail($firstCandidateId)->is_current)->toBeFalse();

    $editor->call('uploadFailed', $recordId, $token)
        ->assertSet('uploadInProgress', false)
        ->assertSet("activeUploads.{$token}.state", 'failed')
        ->assertSet("activeUploads.{$token}.candidate_failed", true)
        ->assertSet("activeUploads.{$token}.failure_kind", 'transfer')
        ->assertSet("operations.upload:{$recordId}.retry_token", $token)
        ->assertSet("operations.upload:{$recordId}.record_id", $recordId)
        ->assertSet("operations.upload:{$recordId}.retry_mode", 'transfer')
        ->assertSet('operations', fn (array $operations): bool => collect($operations)->contains(
            fn (array $operation): bool => $operation['state'] === 'failed'
                && str_contains($operation['error'], __('The video upload failed. Try again.')),
        ));

    expect(Video::query()->findOrFail($firstCandidateId)->status)->toBe(VideoStatus::Failed)
        ->and($previous->fresh()->is_current)->toBeTrue();

    $editor->call('retryUploadTransfer', $recordId, $token)
        ->assertSet('uploadInProgress', true)
        ->assertSet("activeUploads.{$token}.state", 'uploading')
        ->assertSet("activeUploads.{$token}.candidate_failed", false)
        ->assertSet("activeUploads.{$token}.failure_kind", null)
        ->assertSet("activeUploads.{$token}.asset_id", $fixture->token.'-retried-transfer')
        ->assertSet("operations.upload:{$recordId}.state", 'pending');

    $retriedUploads = $editor->get('activeUploads');
    $secondCandidateId = $retriedUploads[$token]['video_id'];
    expect(array_keys($retriedUploads))->toBe([$token])
        ->and($secondCandidateId)->not->toBe($firstCandidateId)
        ->and(Video::query()->where('lesson_id', $recordId)->count())->toBe($before + 2)
        ->and($previous->fresh()->is_current)->toBeTrue()
        ->and(Video::query()->findOrFail($firstCandidateId)->is_current)->toBeFalse()
        ->and(Video::query()->findOrFail($secondCandidateId)->is_current)->toBeFalse()
        ->and(Http::recorded(fn ($request): bool => str_contains($request->url(), '/stream/direct_upload')))->toHaveCount(2);
})->with('course editor contexts');

it('renders target-keyed states while allowing non-conflicting structure and image operations', function (): void {
    [$fixture, $editor] = unifiedEditor('company course');
    $recordId = $fixture->recordIds[0];
    $recordKey = 'lesson:'.$recordId;
    $beforeQuestions = Question::query()->where('lesson_id', $recordId)->count();

    $editor->call('addQuestion', $recordId)
        ->assertSet("operations.structure:add-question:{$recordId}.state", 'succeeded')
        ->assertSet("operations.structure:add-question:{$recordId}.kind", 'structure')
        ->assertSet("operations.structure:add-question:{$recordId}.target_key", $recordKey)
        ->assertSee('data-editor-operation', escape: false)
        ->assertSee('data-operation-state="succeeded"', escape: false)
        ->assertSee('data-record-key="'.$recordKey.'"', escape: false);
    expect(Question::query()->where('lesson_id', $recordId)->count())->toBe($beforeQuestions + 1);

    $editor->call('requestUpload', $recordId, 'pending-training.mp4')
        ->assertSet("operations.upload:{$recordId}.state", 'pending')
        ->assertSet("operations.upload:{$recordId}.kind", 'media')
        ->assertSet("operations.upload:{$recordId}.target_key", $recordKey)
        ->assertSee('data-operation-state="pending"', escape: false);

    $duringUpload = Question::query()->where('lesson_id', $recordId)->count();
    $editor->call('addQuestion', $recordId)
        ->assertSet("operations.structure:add-question:{$recordId}.state", 'succeeded')
        ->assertSet("operations.structure:add-question:{$recordId}.target_key", $recordKey)
        ->assertSee('data-operation-state="succeeded"', escape: false);
    expect(Question::query()->where('lesson_id', $recordId)->count())->toBe($duringUpload + 1);

    $token = array_key_first($editor->get('activeUploads'));
    $editor->call('uploadFailed', $recordId, $token)->assertSet('uploadInProgress', false);

    $image = ContentImage::query()->create([
        'company_id' => $fixture->root->company_id,
        'is_shared' => false,
        'name' => 'guarded-image.png',
        'disk' => 'public',
        'path' => 'content-images/guarded-image.png',
        'mime_type' => 'image/png',
        'size_bytes' => 128,
    ]);
    $beforeContent = $editor->get('records.0.content_markdown');
    $beforeImages = ContentImage::query()->count();
    $editor->set('imageLibraryRecordKey', $recordKey)
        ->set('courseForm.title', $fixture->token.' dirty image guard')
        ->call('selectContentImage', $image->id)
        ->assertSet("operations.media:select-image:{$recordKey}.state", 'succeeded')
        ->assertSet("operations.media:select-image:{$recordKey}.target_key", $recordKey)
        ->assertSet('records.0.content_markdown', $beforeContent)
        ->call('uploadContentImage')
        ->assertSet("operations.media:upload-image:{$recordKey}.state", 'failed')
        ->assertSet("operations.media:upload-image:{$recordKey}.target_key", $recordKey)
        ->assertSet('records.0.content_markdown', $beforeContent)
        ->assertSee('data-operation-kind="media"', escape: false)
        ->assertSee('data-operation-state="failed"', escape: false);
    expect(ContentImage::query()->count())->toBe($beforeImages);
});

it('renders nested structure operations with the actual question and answer targets', function (): void {
    [$fixture, $editor] = unifiedEditor('company course');
    $recordId = $fixture->recordIds[0];
    $questions = Question::query()
        ->where('lesson_id', $recordId)
        ->with('options')
        ->orderBy('position')
        ->get();
    $question = $questions->get(1);
    $option = $question->options->first();

    $editor->call('moveQuestion', $recordId, $question->id, -1)
        ->assertSet("operations.structure:reorder-questions:{$recordId}.target_key", 'question:'.$question->id)
        ->assertSet("operations.structure:reorder-questions:{$recordId}.target_label", $question->prompt)
        ->assertSet("operations.structure:reorder-questions:{$recordId}.action_label", __('Move question'))
        ->assertSee('data-operation-target-label="'.e($question->prompt).'"', escape: false)
        ->assertSeeText(__('Move question completed for :target.', ['target' => $question->prompt]));

    $editor->call('confirmAnswerDestruction', $recordId, $question->id, $option->id)
        ->call('performConfirmedDestructive')
        ->assertSet("operations.structure:remove-option:{$recordId}.target_key", 'option:'.$option->id)
        ->assertSet("operations.structure:remove-option:{$recordId}.target_label", $option->text)
        ->assertSet("operations.structure:remove-option:{$recordId}.action_label", __('Remove answer'))
        ->assertSee('data-operation-target-label="'.e($option->text).'"', escape: false)
        ->assertSeeText(__('Remove answer completed for :target.', ['target' => $option->text]));
});

it('keeps concurrent uploads in separate stable rows through uploading failed processing and ready states', function (): void {
    [$fixture, $editor] = unifiedEditor('company course');
    [$firstRecordId, $secondRecordId] = array_slice($fixture->recordIds, 0, 2);
    $allocation = 0;
    $providerState = 'inprogress';
    $owner = 'company:'.$fixture->root->company_id;

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(function ($request) use (&$allocation, &$providerState, $fixture, $owner) {
        if ($request->method() === 'POST') {
            $allocation++;

            return Http::response(['success' => true, 'result' => [
                'uid' => $fixture->token.'-concurrent-'.$allocation,
                'uploadURL' => 'https://upload.example/concurrent-'.$allocation,
            ]]);
        }

        $assetId = basename($request->url());

        return Http::response(['success' => true, 'result' => [
            'uid' => $assetId,
            'status' => ['state' => $providerState],
            'duration' => $providerState === 'ready' ? 75 : null,
            'requireSignedURLs' => true,
            'playback' => ['hls' => 'https://video.example/'.$assetId.'.m3u8'],
            'meta' => ['oceanix_owner' => $owner],
        ]]);
    });

    $editor->call('requestUpload', $firstRecordId, 'safety-intro.mp4');
    $firstToken = array_key_first($editor->get('activeUploads'));
    $editor->call('requestUpload', $secondRecordId, 'incident-response.mp4');
    $tokens = array_keys($editor->get('activeUploads'));
    $secondToken = collect($tokens)->first(fn (string $token): bool => $token !== $firstToken);

    $editor->assertCount('activeUploads', 2)
        ->assertCount('uploadRows', 2)
        ->assertSet("uploadRows.{$firstToken}.record_key", 'lesson:'.$firstRecordId)
        ->assertSet("uploadRows.{$firstToken}.filename", 'safety-intro.mp4')
        ->assertSet("uploadRows.{$firstToken}.state", 'uploading')
        ->assertSet("uploadRows.{$secondToken}.record_key", 'lesson:'.$secondRecordId)
        ->assertSet("uploadRows.{$secondToken}.filename", 'incident-response.mp4')
        ->assertSet("uploadRows.{$secondToken}.state", 'uploading')
        ->assertSee('data-editor-upload-list', escape: false)
        ->assertSee('data-upload-token="'.$firstToken.'"', escape: false)
        ->assertSee('data-upload-token="'.$secondToken.'"', escape: false)
        ->assertSee('safety-intro.mp4')
        ->assertSee('incident-response.mp4')
        ->assertSee('Uploading');

    $editor->call('uploadFailed', $firstRecordId, $firstToken)
        ->assertSet("uploadRows.{$firstToken}.state", 'failed')
        ->assertSet("uploadRows.{$firstToken}.failure_kind", 'transfer')
        ->assertSet("uploadRows.{$secondToken}.state", 'uploading')
        ->assertSee('Upload failed')
        ->assertSee('data-editor-upload-retry="transfer"', escape: false);
    expect($editor->html())->toMatch('/data-editor-upload[\\s\\S]*data-upload-token="'.preg_quote($firstToken, '/').'"[\\s\\S]*data-upload-state="failed"[\\s\\S]*role="alert"/');

    $editor->call('uploadCompleted', $secondRecordId, $secondToken)
        ->assertSet("uploadRows.{$firstToken}.state", 'failed')
        ->assertSet("uploadRows.{$secondToken}.state", 'processing')
        ->assertSee('Processing');

    $providerState = 'ready';
    $editor->call('uploadCompleted', $secondRecordId, $secondToken)
        ->assertSet("uploadRows.{$firstToken}.state", 'failed')
        ->assertSet("uploadRows.{$secondToken}.state", 'ready')
        ->assertSet("activeUploads.{$firstToken}.state", 'failed')
        ->assertSee('Ready')
        ->assertSee('Upload failed');

    expect(array_keys($editor->get('uploadRows')))->toBe([$firstToken, $secondToken]);
});

it('reorders persisted questions by stable id and saves the moved value to the same record', function (): void {
    [$fixture, $editor] = unifiedEditor('standalone shared module');
    $recordId = $fixture->recordIds[0];
    $questions = Question::query()->where('lesson_id', $recordId)->orderBy('position')->get();
    $moved = $questions[1];

    $editor->call('moveQuestion', $recordId, $moved->id, -1)
        ->assertSet('records.0.questions.0.id', $moved->id)
        ->set('records.0.questions.0.prompt', $fixture->token.' Moved and edited')
        ->call('saveDraft', false)
        ->assertSet('saveState', 'saved');

    expect($moved->fresh()->position)->toBe(1)
        ->and($moved->fresh()->prompt)->toBe($fixture->token.' Moved and edited')
        ->and($questions[0]->fresh()->position)->toBe(2);

    Livewire::test($fixture->context->component, $fixture->routeParameters())
        ->assertSet('records.0.questions.0.id', $moved->id)
        ->assertSet('records.0.questions.0.prompt', $fixture->token.' Moved and edited');
});
