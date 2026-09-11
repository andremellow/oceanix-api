<?php

use App\Enums\VideoStatus;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\CourseVersionModule;
use App\Models\Lesson;
use App\Models\ModuleVersion;
use App\Models\Question;
use App\Models\Video;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CourseEditor\EditorContextCase;
use Tests\Support\CourseEditor\EditorFixture;

/** @return array{EditorFixture, Testable} */
function legacyCompanyEditor(): array
{
    $fixture = EditorFixture::create(EditorContextCase::companyCourse());
    Livewire::actingAs($fixture->user);

    return [$fixture, Livewire::test('courses.editor', $fixture->routeParameters())];
}

it('adds a lesson and persists it immediately with a stable id', function (): void {
    [$fixture, $editor] = legacyCompanyEditor();
    $version = $fixture->root->versions()->firstOrFail();
    $before = $version->lessons()->count();

    $editor->call('addLesson')->assertSet('editorDirty', false)->assertCount('records', $before + 1);

    $created = $version->lessons()->orderByDesc('position')->firstOrFail();
    expect($created->id)->toBeGreaterThan(0)
        ->and(collect($editor->get('records'))->pluck('id'))->toContain($created->id);
});

it('keeps authored lesson fields staged until explicit Save', function (): void {
    [$fixture, $editor] = legacyCompanyEditor();
    $lesson = Lesson::query()->findOrFail($fixture->recordIds[0]);
    $before = $lesson->only(['title', 'description', 'minimum_watch_percentage']);

    $editor->set('records.0.title', $fixture->token.' Saved lesson')
        ->set('records.0.description', $fixture->token.' Saved description')
        ->set('records.0.minimum_watch_percentage', 95)
        ->assertSet('saveState', 'dirty');

    expect($lesson->fresh()->only(array_keys($before)))->toBe($before);

    $editor->call('saveDraft', false)->assertSet('saveState', 'saved');
    expect($lesson->fresh()->title)->toBe($fixture->token.' Saved lesson')
        ->and($lesson->fresh()->description)->toBe($fixture->token.' Saved description')
        ->and($lesson->fresh()->minimum_watch_percentage)->toBe(95);
});

it('persists the inclusive watch threshold boundaries', function (int $threshold): void {
    [$fixture, $editor] = legacyCompanyEditor();
    $editor->set('records.0.minimum_watch_percentage', $threshold)->call('saveDraft', false)->assertSet('saveState', 'saved');

    expect(Lesson::query()->findOrFail($fixture->recordIds[0])->minimum_watch_percentage)->toBe($threshold);
})->with([1, 100]);

it('rejects invalid watch thresholds without changing persisted tracking data', function (mixed $threshold): void {
    [$fixture, $editor] = legacyCompanyEditor();
    $lesson = Lesson::query()->findOrFail($fixture->recordIds[0]);
    $before = $lesson->minimum_watch_percentage;

    $editor->set('records.0.minimum_watch_percentage', $threshold)
        ->call('saveDraft', false)
        ->assertSet('saveState', 'validation-error')
        ->assertSet('editorDirty', true);

    expect($lesson->fresh()->minimum_watch_percentage)->toBe($before);
})->with([0, 101, 42.5]);

it('creates a question with two options through an immediate structural action', function (): void {
    [$fixture, $editor] = legacyCompanyEditor();
    $recordId = $fixture->recordIds[0];
    $before = Question::query()->where('lesson_id', $recordId)->count();

    $editor->call('addQuestion', $recordId)
        ->assertCount('records.0.questions', $before + 1)
        ->assertSet('editorDirty', false);

    expect(Question::query()->where('lesson_id', $recordId)->orderByDesc('position')->firstOrFail()->options()->count())->toBe(2);
});

it('keeps exactly one selected answer and commits it only with Save', function (): void {
    [$fixture, $editor] = legacyCompanyEditor();
    $question = Question::query()->where('lesson_id', $fixture->recordIds[0])->with('options')->firstOrFail();
    $first = $question->options[0];
    $second = $question->options[1];

    $editor->set('records.0.questions.0.options.0.is_correct', false)
        ->set('records.0.questions.0.options.1.is_correct', true)
        ->assertSet('records.0.questions.0.options.0.is_correct', false)
        ->assertSet('records.0.questions.0.options.1.is_correct', true)
        ->assertSet('editorDirty', true);

    expect($first->fresh()->is_correct)->toBeTrue()->and($second->fresh()->is_correct)->toBeFalse();
    $editor->call('saveDraft', false)->assertSet('saveState', 'saved');
    expect($first->fresh()->is_correct)->toBeFalse()->and($second->fresh()->is_correct)->toBeTrue();
});

it('shows and preserves both inventories for a mixed draft while blocking mutation', function (): void {
    [$fixture] = legacyCompanyEditor();
    $course = Course::query()->findOrFail($fixture->root->id);
    $version = $course->versions()->firstOrFail();
    $module = ModuleVersion::factory()->create();
    CourseVersionModule::query()->create(['course_version_id' => $version->id, 'lesson_id' => $module->id, 'position' => 4, 'is_required' => true]);
    $lessonIds = $version->lessons()->pluck('id')->all();

    $editor = Livewire::test('courses.editor', ['course' => $course])
        ->assertSet('compositionMode', 'mixed')
        ->assertCount('records', 3)
        ->assertCount('preservedRecords', 1)
        ->assertSee(__('ui.mixed_composition_title'));

    $editor->call('addLesson');
    expect($version->fresh()->lessons()->pluck('id')->all())->toBe($lessonIds)
        ->and($version->fresh()->moduleCompositions()->where('lesson_id', $module->id)->exists())->toBeTrue();
});

it('never opens a shared course editor from tenant context', function (): void {
    $shared = Course::factory()->shared()->draft()->create();
    CourseVersion::factory()->create(['course_id' => $shared]);

    $this->actingAs(adminUser())
        ->get(route('courses.editor', ['company' => currentCompany(), 'course' => $shared]))
        ->assertNotFound();
});

it('keeps each persisted video state available through the rich-content video control', function (VideoStatus $status): void {
    [$fixture] = legacyCompanyEditor();
    Video::factory()->create(['lesson_id' => $fixture->recordIds[0], 'company_id' => $fixture->root->company_id, 'status' => $status, 'is_current' => true]);

    Livewire::test('courses.editor', $fixture->routeParameters())
        ->assertSee('data-editor-media-action="open-library"', escape: false)
        ->assertSee('data-editor-action-detail="open-video-library"', escape: false)
        ->assertDontSeeText('No video attached')
        ->assertDontSeeText('Choose or upload video')
        ->assertDontSeeText('Replace video')
        ->assertDontSeeText('Remove video');
})->with(VideoStatus::cases());

it('denies the editor after the update permission is revoked', function (): void {
    [$fixture] = legacyCompanyEditor();
    $fixture->user->roles()->detach();

    $this->actingAs($fixture->user)
        ->get(route('courses.editor', $fixture->routeParameters()))
        ->assertForbidden();
});

it('keeps the version title synchronized on Save but freezes a published version', function (): void {
    [$fixture, $editor] = legacyCompanyEditor();
    $course = Course::query()->findOrFail($fixture->root->id);
    $version = $course->versions()->firstOrFail();
    $editor->set('courseForm.title', $fixture->token.' Renamed')->call('saveDraft', false)->assertSet('saveState', 'saved');

    expect($version->fresh()->title)->toBe($fixture->token.' Renamed');
    $version->update(['status' => 'published', 'published_at' => now()]);
    $course->update(['title' => $fixture->token.' Catalog renamed']);
    expect($version->fresh()->title)->toBe($fixture->token.' Renamed');
});
