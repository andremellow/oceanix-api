<?php

use App\Actions\Videos\LinkExistingVideo;
use App\Models\Account;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Module;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function editableAssessmentModule(): array
{
    $module = Module::factory()->shared()->create(['status' => 'draft']);
    $question = Question::factory()->create(['lesson_id' => $module->id, 'company_id' => null, 'type' => 'single_choice', 'prompt' => 'Safety question']);
    $first = QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'company_id' => null, 'position' => 1, 'text' => 'First answer']);
    $second = QuestionOption::factory()->create(['question_id' => $question->id, 'company_id' => null, 'position' => 2, 'text' => 'Second answer']);

    return [$module, $question, $first, $second];
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

it('blocks structural changes and publication while assessment edits are dirty', function (): void {
    $account = Account::factory()->platformAdmin()->create();
    [$module, $question] = editableAssessmentModule();
    $before = Question::query()->where('lesson_id', $module->id)->count();
    $this->withSession(['platform_account_id' => $account->id]);

    Livewire::test('platform.shared-modules.editor', ['module' => $module])
        ->set('questions.0.prompt', 'Unsaved prompt')
        ->call('markAssessmentDirty')
        ->call('addQuestion')
        ->assertSet('assessmentError', __('Save the assessment before adding questions or answers.'))
        ->call('publish')
        ->assertHasErrors('publish');

    expect(Question::query()->where('lesson_id', $module->id)->count())->toBe($before)
        ->and($question->fresh()->prompt)->toBe('Safety question')
        ->and($module->fresh()->getRawOriginal('status'))->toBe('draft');
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
