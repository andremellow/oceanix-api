<?php

use App\Actions\Videos\LinkExistingVideo;
use App\Enums\VideoStatus;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Module;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function editableAssessmentModule(): array
{
    $module = Module::factory()->shared()->create(['status' => 'draft']);
    $question = Question::factory()->create(['lesson_id' => $module->id, 'company_id' => null, 'type' => 'single_choice', 'prompt' => 'Safety question']);
    $first = QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'company_id' => null, 'position' => 1, 'text' => 'First answer']);
    $second = QuestionOption::factory()->create(['question_id' => $question->id, 'company_id' => null, 'position' => 2, 'text' => 'Second answer']);

    return [$module, $question, $first, $second];
}

function expectFullWidthAssessmentOptionFields(string $html, array $models): void
{
    foreach ($models as $model) {
        $fieldPattern = '/<ui-field(?=[^>]*class="[^"]*\\bflex-1\\b)(?=[^>]*class="[^"]*\\bmin-w-0\\b)[^>]*>.*?wire:model.defer="'.preg_quote($model, '/').'".*?<\\/ui-field>/s';

        expect(preg_match_all($fieldPattern, $html))->toBe(1);
    }
}

it('saves and reloads the complete assessment from the shared course editor', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module, $question, $first, $second] = editableAssessmentModule();
    $course = Course::factory()->shared()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $module->id, 'position' => 1, 'is_required' => true]);
    $this->withSession(['platform_account_id' => $account->id]);

    $editor = Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->assertSee(__('Save and continue'))
        ->assertSeeInOrder([__('Question :number', ['number' => 1]), __('Add question'), __('Save and continue')])
        ->assertSee('wire:model.defer="modules.0.questions.0.prompt"', escape: false)
        ->set('modules.0.questions.0.prompt', 'Last typed character!')
        ->set('modules.0.questions.0.options.0.is_correct', false)
        ->set('modules.0.questions.0.options.1.is_correct', true)
        ->call('markAssessmentDirty', $module->id)
        ->call('saveDraft', false)
        ->assertHasNoErrors()
        ->assertSet("assessmentDirty.{$module->id}", false)
        ->assertDispatched('assessment-saved');

    expect(substr_count($editor->html(), 'wire:click="addQuestion(0)"'))->toBe(1)
        ->and(substr_count($editor->html(), 'wire:click="saveDraft(false)"'))->toBe(1)
        ->and($question->fresh()->prompt)->toBe('Last typed character!')
        ->and($first->fresh()->is_correct)->toBeFalse()
        ->and($second->fresh()->is_correct)->toBeTrue();

    expectFullWidthAssessmentOptionFields($editor->html(), [
        'modules.0.questions.0.options.0.text',
        'modules.0.questions.0.options.1.text',
    ]);
});

it('provides assessment parity in the standalone shared module editor', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module, $question, $first, $second] = editableAssessmentModule();
    $this->withSession(['platform_account_id' => $account->id]);

    $editor = Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->assertSee(__('Assessment'))
        ->assertSee(__('Add question'))
        ->assertSee(__('Add option'))
        ->assertSee(__('Save and continue'))
        ->assertSeeInOrder([__('Question :number', ['number' => 1]), __('Add question'), __('Save and continue')])
        ->assertSee('wire:model.defer="questions.0.prompt"', escape: false)
        ->set('title', 'Globally saved module')
        ->set('description', 'Saved description')
        ->set('contentMarkdown', '# Saved content')
        ->set('minimumWatchPercentage', 85)
        ->set('passingScore', 75)
        ->set('questions.0.prompt', 'Standalone saved question')
        ->call('markAssessmentDirty')
        ->call('saveDraft', false)
        ->assertHasNoErrors()
        ->assertSet('assessmentDirty', false)
        ->assertDispatched('assessment-saved');

    expect(substr_count($editor->html(), 'wire:click="addQuestion"'))->toBe(1)
        ->and(substr_count($editor->html(), 'wire:click="saveDraft(false)"'))->toBe(1)
        ->and($question->fresh()->prompt)->toBe('Standalone saved question')
        ->and($module->fresh()->title)->toBe('Globally saved module')
        ->and($module->fresh()->description)->toBe('Saved description')
        ->and($module->fresh()->content_markdown)->toBe('# Saved content')
        ->and($module->fresh()->minimum_watch_percentage)->toBe(85)
        ->and($module->fresh()->passing_score)->toBe(75);

    expectFullWidthAssessmentOptionFields($editor->html(), [
        'questions.0.options.0.text',
        'questions.0.options.1.text',
    ]);
});

it('rolls back every module when a later module in the course draft is invalid', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$first, $firstQuestion] = editableAssessmentModule();
    [$second] = editableAssessmentModule();
    $course = Course::factory()->shared()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    foreach ([$first, $second] as $position => $module) {
        CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $module->id, 'position' => $position + 1, 'is_required' => true]);
    }
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->set('modules.0.title', 'Must roll back')
        ->set('modules.0.questions.0.prompt', 'Must also roll back')
        ->set('modules.1.questions.0.prompt', '')
        ->set('editorDirty', true)
        ->call('saveDraft', false)
        ->assertSet('saveError', fn (?string $error): bool => filled($error));

    expect($first->fresh()->title)->not->toBe('Must roll back')
        ->and($firstQuestion->fresh()->prompt)->toBe('Safety question');
});

it('preserves staged values and rejects a stale global module draft revision', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module] = editableAssessmentModule();
    $this->withSession(['platform_account_id' => $account->id]);

    $editor = Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->set('title', 'My staged title')
        ->call('markAssessmentDirty');

    $module->update(['title' => 'Concurrent title']);

    $editor->call('saveDraft', false)
        ->assertSet('title', 'My staged title')
        ->assertSet('assessmentDirty', true)
        ->assertSet('saveError', __('This module changed elsewhere. Reload the page before saving again.'));

    expect($module->fresh()->title)->toBe('Concurrent title');
});

it('stages structural changes without writing and blocks publication until they are saved', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module, $question] = editableAssessmentModule();
    $before = Question::query()->where('lesson_id', $module->id)->count();
    $this->withSession(['platform_account_id' => $account->id]);

    $editor = Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->set('questions.0.prompt', 'Unsaved prompt')
        ->call('markAssessmentDirty')
        ->call('addQuestion')
        ->assertCount('questions', 2)
        ->assertSet('questions.1.id', null)
        ->assertDispatched('assessment-dirty')
        ->call('publish')
        ->assertHasErrors('publish');

    expect(Question::query()->where('lesson_id', $module->id)->count())->toBe($before)
        ->and($question->fresh()->prompt)->toBe('Safety question')
        ->and($module->fresh()->getRawOriginal('status'))->toBe('draft');

    $editor->call('saveDraft', false)->assertHasNoErrors()->assertCount('questions', 2);
    expect(Question::query()->where('lesson_id', $module->id)->count())->toBe($before + 1)
        ->and($question->fresh()->prompt)->toBe('Unsaved prompt');
});

it('atomically stages mixed assessment CRUD with temporary ids in the standalone editor', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module, $question, $first, $second] = editableAssessmentModule();
    $this->withSession(['platform_account_id' => $account->id]);

    $editor = Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->set('questions.0.prompt', 'Existing edited')
        ->call('markAssessmentDirty')
        ->call('addOption', 0)
        ->set('questions.0.options.2.text', 'Temporary third')
        ->call('addQuestion')
        ->call('addQuestion')
        ->call('removeQuestion', 2)
        ->set('questions.1.prompt', 'Temporary question')
        ->call('addOption', 1)
        ->set('questions.1.options.2.text', 'Temporary extra')
        ->call('removeOption', 1, 1)
        ->call('removeOption', 0, 1)
        ->assertSet('questions.1.id', null)
        ->assertSet('questions.1.options.1.id', null);

    expect(Question::query()->where('lesson_id', $module->id)->count())->toBe(1)
        ->and(QuestionOption::query()->where('question_id', $question->id)->count())->toBe(2);

    $editor->call('saveDraft', false)
        ->assertHasNoErrors()
        ->assertSet('assessmentDirty', false)
        ->assertCount('questions', 2)
        ->assertSet('questions.1.prompt', 'Temporary question');

    $saved = $module->questions()->with('options')->orderBy('position')->get();
    expect($saved)->toHaveCount(2)
        ->and($saved[0]->prompt)->toBe('Existing edited')
        ->and($saved[0]->options)->toHaveCount(2)
        ->and($saved[0]->options->pluck('text')->all())->toBe(['First answer', 'Temporary third'])
        ->and($saved[1]->prompt)->toBe('Temporary question')
        ->and($saved[1]->options->pluck('text')->all())->toBe(['Option 1', 'Temporary extra'])
        ->and(QuestionOption::query()->whereKey($second->id)->exists())->toBeFalse();

    $editor->call('addOption', 1)->call('saveDraft', false)->assertHasNoErrors();
    expect($module->questions()->where('position', 2)->sole()->options()->count())->toBe(3);
});

it('saves temporary assessment structure before closing the standalone editor', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module] = editableAssessmentModule();
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->call('addQuestion')
        ->set('questions.1.prompt', 'Saved while closing')
        ->call('saveDraft', true)
        ->assertRedirect(route('platform.shared-modules.show', ['module' => $module]));

    expect($module->questions()->where('prompt', 'Saved while closing')->exists())->toBeTrue();
});

it('preserves temporary assessment structure when a concurrent save makes its revision stale', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module, $question] = editableAssessmentModule();
    $this->withSession(['platform_account_id' => $account->id]);

    $stale = Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->call('addQuestion')
        ->set('questions.1.prompt', 'Unsaved temporary question')
        ->call('removeQuestion', 0);

    $module->update(['title' => 'Saved in another tab']);

    $stale->call('saveDraft', false)
        ->assertSet('assessmentDirty', true)
        ->assertSet('questions.0.id', null)
        ->assertSet('questions.0.prompt', 'Unsaved temporary question')
        ->assertSet('saveError', __('This module changed elsewhere. Reload the page before saving again.'));

    expect($module->questions()->count())->toBe(1)
        ->and($question->fresh()->prompt)->toBe('Safety question');
});

it('preserves temporary structures after validation failure and saves them atomically in the course editor', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module, $question] = editableAssessmentModule();
    $course = Course::factory()->shared()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $module->id, 'position' => 1, 'is_required' => true]);
    $this->withSession(['platform_account_id' => $account->id]);

    $editor = Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->set('modules.0.questions.0.prompt', 'Existing staged')
        ->call('markAssessmentDirty', $module->id)
        ->call('addQuestion', 0)
        ->assertDispatched('assessment-dirty')
        ->set('modules.0.questions.1.prompt', 'Temporary staged')
        ->call('addOption', 0, 1)
        ->set('modules.0.questions.1.options.2.text', '')
        ->call('saveDraft', false)
        ->assertSet('editorDirty', true)
        ->assertSet('modules.0.questions.1.prompt', 'Temporary staged')
        ->assertSet('modules.0.questions.1.options.2.text', '')
        ->assertSet('saveError', fn (?string $error): bool => filled($error));

    expect($question->fresh()->prompt)->toBe('Safety question')
        ->and($module->questions()->count())->toBe(1);

    $editor->set('modules.0.questions.1.options.2.text', 'Valid third')
        ->call('removeQuestion', 0, 0)
        ->call('saveDraft', false)
        ->assertHasNoErrors()
        ->assertSet('editorDirty', false)
        ->assertCount('modules.0.questions', 1);

    $saved = $module->questions()->with('options')->sole();
    expect($saved->prompt)->toBe('Temporary staged')
        ->and($saved->position)->toBe(1)
        ->and($saved->options)->toHaveCount(3)
        ->and(Question::query()->whereKey($question->id)->exists())->toBeFalse();
});

it('does not convert revoked platform access into a retryable assessment error in either editor', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module, $question] = editableAssessmentModule();
    $course = Course::factory()->shared()->draft()->create();
    $courseVersion = CourseVersion::factory()->create(['course_id' => $course]);
    CourseVersionModule::query()->create(['course_version_id' => $courseVersion->id, 'lesson_id' => $module->id, 'position' => 1, 'is_required' => true]);
    $this->withSession(['platform_account_id' => $account->id]);

    $courseEditor = Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->set('modules.0.questions.0.prompt', 'Must not save')
        ->call('markAssessmentDirty', $module->id);
    $moduleEditor = Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->set('questions.0.prompt', 'Must not save either')
        ->call('markAssessmentDirty');

    $account->update(['is_platform_admin' => false]);

    $courseEditor->call('saveDraft', false)->assertForbidden();
    $moduleEditor->call('saveDraft', false)->assertForbidden();

    expect($question->fresh()->prompt)->toBe('Safety question');
});

it('does not convert ineligible module version denials into retryable errors in either editor', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module] = editableAssessmentModule();
    $course = Course::factory()->shared()->draft()->create();
    $courseVersion = CourseVersion::factory()->create(['course_id' => $course]);
    CourseVersionModule::query()->create(['course_version_id' => $courseVersion->id, 'lesson_id' => $module->id, 'position' => 1, 'is_required' => true]);
    $this->withSession(['platform_account_id' => $account->id]);

    $courseEditor = Livewire::test('platform.shared-courses.editor', ['course' => $course])->call('markAssessmentDirty', $module->id);
    $moduleEditor = Livewire::test('platform.shared-modules.editor', ['module' => $module])->call('markAssessmentDirty');
    $module->update(['status' => 'published']);

    expect(fn () => $courseEditor->call('saveDraft', false))->toThrow(LogicException::class);
    expect(fn () => $moduleEditor->call('saveDraft', false))->toThrow(LogicException::class);

    $courseEditor->assertSet("assessmentErrors.{$module->id}", null);
    $moduleEditor->assertSet('assessmentError', null);
});

it('can save assessment changes after another module field autosaves in the same editor', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module, $question] = editableAssessmentModule();
    $course = Course::factory()->shared()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $module->id, 'position' => 1, 'is_required' => true]);
    $this->withSession(['platform_account_id' => $account->id]);

    $component = Livewire::test('platform.shared-courses.editor', ['course' => $course]);
    Carbon::setTestNow(now()->addSeconds(2));

    $component->set('modules.0.title', 'Autosaved title')
        ->set('modules.0.questions.0.prompt', 'Assessment edited after title')
        ->call('markAssessmentDirty', $module->id)
        ->call('saveDraft', false)
        ->assertHasNoErrors()
        ->assertSet("assessmentDirty.{$module->id}", false);

    expect($question->fresh()->prompt)->toBe('Assessment edited after title');
});

it('saves a fifty question module through the global save action', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    $module = Module::factory()->shared()->create(['status' => 'draft']);
    foreach (range(1, 50) as $position) {
        $question = Question::factory()->create(['lesson_id' => $module->id, 'company_id' => null, 'type' => 'single_choice', 'position' => $position]);
        QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'company_id' => null, 'position' => 1]);
        QuestionOption::factory()->create(['question_id' => $question->id, 'company_id' => null, 'position' => 2]);
    }
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->assertCount('questions', 50)
        ->set('questions.49.prompt', 'The fiftieth question, including its last character!')
        ->call('markAssessmentDirty')
        ->call('saveDraft', false)
        ->assertHasNoErrors()
        ->assertSet('assessmentDirty', false);

    expect($module->questions()->where('position', 50)->sole()->prompt)
        ->toBe('The fiftieth question, including its last character!');
});

it('preserves staged module values and their original revision when selecting a library video', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module] = editableAssessmentModule();
    $course = Course::factory()->shared()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $module->id, 'position' => 1, 'is_required' => true]);
    $this->withSession(['platform_account_id' => $account->id]);

    $link = Mockery::mock(LinkExistingVideo::class);
    $link->shouldReceive('handle')->once();
    app()->instance(LinkExistingVideo::class, $link);

    $editor = Livewire::test('platform.shared-courses.editor', ['course' => $course]);
    $originalRevision = $editor->get("moduleRevisions.{$module->id}");
    $editor
        ->set('modules.0.title', 'Staged title ending in Z')
        ->set('editorDirty', true)
        ->set('videoEditorModel', 'modules.0.content_markdown')
        ->set('videoLibraryItems', [[
            'asset_id' => 'ready-video', 'status' => 'ready', 'preview_url' => null,
            'thumbnail_url' => null, 'title' => 'Ready video', 'aspect_ratio' => '16/9',
            'duration' => '1:00', 'status_label' => 'Ready',
        ]]);

    $module->update(['title' => 'Concurrent title']);

    $editor->call('selectLibraryVideo', 'ready-video')
        ->assertSet('modules.0.title', 'Staged title ending in Z')
        ->assertSet("moduleRevisions.{$module->id}", $originalRevision)
        ->call('saveDraft', false)
        ->assertSet('saveError', __('This module changed elsewhere. Reload the page before saving again.'));

    expect($module->fresh()->title)->toBe('Concurrent title');
});

it('keeps the shared course editor blocked until every concurrent upload settles', function (): void {
    Http::fake(['api.cloudflare.com/*' => Http::response(['result' => ['uid' => 'course-upload', 'uploadURL' => 'https://upload.example/course']])]);
    $account = Account::factory()->platformAdmin()->create();
    [$module] = editableAssessmentModule();
    $course = Course::factory()->shared()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $module->id, 'position' => 1, 'is_required' => true]);
    $this->withSession(['platform_account_id' => $account->id]);

    $editor = Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->call('requestUpload', 0)
        ->call('requestUpload', 0)
        ->assertSet('uploadInProgress', true);

    $tokens = array_keys($editor->get('activeUploads'));
    $uploads = $editor->get('activeUploads');
    expect($tokens)->toHaveCount(2);
    expect($uploads[$tokens[0]]['video_id'])->not->toBe($uploads[$tokens[1]]['video_id']);

    $editor->call('uploadFailed', 0, $tokens[0])
        ->assertSet('uploadInProgress', true);
    expect($editor->get('activeUploads'))->toHaveCount(1);

    $editor->call('uploadCompleted', 0, $tokens[1])
        ->assertSet('uploadInProgress', false);
    expect($editor->get('activeUploads'))->toBe([])
        ->and(Video::query()->findOrFail($uploads[$tokens[1]]['video_id'])->status)->toBe(VideoStatus::Processing)
        ->and(Video::query()->findOrFail($uploads[$tokens[0]]['video_id'])->status)->toBe(VideoStatus::Failed);
});

it('cleans up failed concurrent uploads in the standalone shared module editor', function (): void {
    Http::fake(['api.cloudflare.com/*' => Http::response(['result' => ['uid' => 'module-upload', 'uploadURL' => 'https://upload.example/module']])]);
    $account = Account::factory()->platformAdmin()->create();
    [$module] = editableAssessmentModule();
    $this->withSession(['platform_account_id' => $account->id]);

    $editor = Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->call('requestUpload', 0)
        ->call('requestUpload', 0)
        ->assertSet('uploadInProgress', true);

    $tokens = array_keys($editor->get('activeUploads'));
    $uploads = $editor->get('activeUploads');
    expect($tokens)->toHaveCount(2);

    $editor->call('uploadCompleted', 0, $tokens[0])
        ->assertSet('uploadInProgress', true);
    $editor->call('uploadFailed', 0, $tokens[1])
        ->assertSet('uploadInProgress', false);
    expect($editor->get('activeUploads'))->toBe([])
        ->and(Video::query()->findOrFail($uploads[$tokens[0]]['video_id'])->status)->toBe(VideoStatus::Processing)
        ->and(Video::query()->findOrFail($uploads[$tokens[1]]['video_id'])->status)->toBe(VideoStatus::Failed);
});

it('keeps video management inside the shared module content editor', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module] = editableAssessmentModule();
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->assertSee('oceanix-open-video-library', false)
        ->assertDontSee(__('Replace video'))
        ->assertDontSee(__('Watch threshold (%)'));
});

it('round trips multiple-choice answers through the shared course editor', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module, $question, $first, $second] = editableAssessmentModule();
    $course = Course::factory()->shared()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $module->id, 'position' => 1, 'is_required' => true]);
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->set('modules.0.questions.0.type', 'multiple_choice')
        ->set('modules.0.questions.0.options.0.is_correct', true)
        ->set('modules.0.questions.0.options.1.is_correct', true)
        ->call('markAssessmentDirty', $module->id)
        ->call('saveDraft', false)
        ->assertHasNoErrors();

    expect($question->fresh()->type->value)->toBe('multiple_choice')
        ->and($first->fresh()->is_correct)->toBeTrue()
        ->and($second->fresh()->is_correct)->toBeTrue();

    $editor = Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->assertSet('modules.0.questions.0.type', 'multiple_choice')
        ->assertSeeHtml('type="checkbox"')
        ->assertSeeHtml('wire:model.defer="modules.0.questions.0.options.0.is_correct"')
        ->assertSeeHtml('wire:model.defer="modules.0.questions.0.options.1.is_correct"');

    expectFullWidthAssessmentOptionFields($editor->html(), [
        'modules.0.questions.0.options.0.text',
        'modules.0.questions.0.options.1.text',
    ]);
});

it('rejects converting multiple choice with two correct answers to single choice atomically', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module, $question, $first, $second] = editableAssessmentModule();
    $question->update(['type' => 'multiple_choice']);
    $second->update(['is_correct' => true]);
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->set('questions.0.type', 'single_choice')
        ->call('markAssessmentDirty')
        ->call('saveDraft', false)
        ->assertSet('saveError', __('Choose exactly one correct answer.'));

    expect($question->fresh()->type->value)->toBe('multiple_choice')
        ->and($first->fresh()->is_correct)->toBeTrue()
        ->and($second->fresh()->is_correct)->toBeTrue();
});

it('binds an upload callback to the original module even if its rendered index changes', function (): void {
    Http::fake(['api.cloudflare.com/*' => Http::response(['result' => ['uid' => 'tampered-upload', 'uploadURL' => 'https://upload.example/tampered']])]);
    $account = Account::factory()->platformAdmin()->create();
    [$first] = editableAssessmentModule();
    [$second] = editableAssessmentModule();
    $course = Course::factory()->shared()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $first->id, 'position' => 1, 'is_required' => true]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $second->id, 'position' => 2, 'is_required' => true]);
    $this->withSession(['platform_account_id' => $account->id]);

    $editor = Livewire::test('platform.shared-courses.editor', ['course' => $course])->call('requestUpload', 0);
    $token = array_key_first($editor->get('activeUploads'));
    $videoId = $editor->get("activeUploads.{$token}.video_id");

    $editor->call('uploadFailed', 1, $token)->assertOk();

    expect(Video::query()->findOrFail($videoId))
        ->lesson_id->toBe($first->id)
        ->status->toBe(VideoStatus::Failed);
});

it('round trips multiple-choice answers through the standalone module editor', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module, $question, $first, $second] = editableAssessmentModule();
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->set('questions.0.type', 'multiple_choice')
        ->set('questions.0.options.0.is_correct', true)
        ->set('questions.0.options.1.is_correct', true)
        ->call('markAssessmentDirty')
        ->call('saveDraft', false)
        ->assertHasNoErrors();

    expect($question->fresh()->type->value)->toBe('multiple_choice')
        ->and($first->fresh()->is_correct)->toBeTrue()
        ->and($second->fresh()->is_correct)->toBeTrue();

    $editor = Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->assertSet('questions.0.type', 'multiple_choice')
        ->assertSeeHtml('type="checkbox"')
        ->assertSeeHtml('wire:model.defer="questions.0.options.0.is_correct"')
        ->assertSeeHtml('wire:model.defer="questions.0.options.1.is_correct"');

    expectFullWidthAssessmentOptionFields($editor->html(), [
        'questions.0.options.0.text',
        'questions.0.options.1.text',
    ]);
});

it('preserves canonical media directives during a standalone title-only save', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module] = editableAssessmentModule();
    $canonical = "# Intro\n\n:::image{src=\"https://cdn.example/safety.png\" alt=\"Safety\" align=\"right\" width=\"40%\"}\n\n:::video\n";
    $module->update(['content_markdown' => $canonical]);
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->set('title', 'Title only')
        ->call('markAssessmentDirty')
        ->call('saveDraft', false)
        ->assertHasNoErrors();

    expect($module->fresh()->content_markdown)->toBe($canonical);
});

it('preserves canonical media directives during a course question-only save', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module] = editableAssessmentModule();
    $canonical = "Text\n\n:::image{src=\"https://cdn.example/diagram.png\" alt=\"Diagram\" align=\"left\" width=\"75%\"}\n\n:::video\n";
    $module->update(['content_markdown' => $canonical]);
    $course = Course::factory()->shared()->draft()->create();
    $version = CourseVersion::factory()->create(['course_id' => $course]);
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $module->id, 'position' => 1, 'is_required' => true]);
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire::test('platform.shared-courses.editor', ['course' => $course])
        ->set('modules.0.questions.0.prompt', 'Question only')
        ->call('markAssessmentDirty', $module->id)
        ->call('saveDraft', false)
        ->assertHasNoErrors();

    expect($module->fresh()->content_markdown)->toBe($canonical);
});
