<?php

use App\Enums\QuestionType;
use App\Enums\VideoStatus;
use App\Models\Question;
use App\Models\QuestionAttempt;
use App\Models\QuestionOption;
use Livewire\Livewire;

beforeEach(fn () => fakeCloudflarePlayback());

it('shows authored lesson content between the video and assessment', function (): void {
    [$assignment, $lesson, $question] = trainableAssignment();
    $lesson->update(['content_markdown' => 'TEMPORARILY-HIDDEN-RICH-CONTENT']);

    Livewire::actingAs($assignment->user)
        ->test('training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])
        ->assertSee($question->prompt)
        ->assertSee('TEMPORARILY-HIDDEN-RICH-CONTENT')
        ->assertDontSee('x-data="lessonPlayer', false)
        ->assertSet('playbackUrl', null);
});

it('places the tracked player at the authored video block', function (): void {
    [$assignment, $lesson] = trainableAssignment();
    $lesson->update(['content_markdown' => '<p>Before the video</p><div data-oceanix-video></div><p>After the video</p>']);

    $html = Livewire::actingAs($assignment->user)
        ->test('training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])
        ->html();

    expect(strpos($html, 'Before the video'))->toBeLessThan(strpos($html, 'x-data="lessonPlayer'))
        ->and(strpos($html, 'x-data="lessonPlayer'))->toBeLessThan(strpos($html, 'After the video'));
});

it('places the tracked player at a legacy markdown video block', function (): void {
    [$assignment, $lesson] = trainableAssignment();
    $lesson->update(['content_markdown' => "Before the video\n\n:::video\n\nAfter the video"]);

    $html = Livewire::actingAs($assignment->user)
        ->test('training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])
        ->html();

    expect(strpos($html, 'Before the video'))->toBeLessThan(strpos($html, 'x-data="lessonPlayer'))
        ->and(strpos($html, 'x-data="lessonPlayer'))->toBeLessThan(strpos($html, 'After the video'));
});

it('shows a useful unavailable state when playback authorization fails', function (): void {
    [$assignment, $lesson] = trainableAssignment();
    $lesson->video->update(['status' => VideoStatus::Processing]);

    Livewire::actingAs($assignment->user)
        ->test('training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])
        ->assertSet('playbackUrl', null)
        ->assertSet('playbackError', __('ui.video_playback_failed_help'))
        ->assertSee(__('ui.video_playback_failed'));
});

it('seeds a multiple-choice question with an array so one tick does not select them all', function (): void {
    [$assignment, $lesson] = trainableAssignment();
    $question = Question::factory()->multipleChoice()->create(['lesson_id' => $lesson->id, 'position' => 2]);
    QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'position' => 1]);
    QuestionOption::factory()->create(['question_id' => $question->id, 'position' => 2]);

    Livewire::actingAs($assignment->user)
        ->test('training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])
        ->assertSet("selected.{$question->id}", []);
});

it('records only the option that was ticked', function (): void {
    [$assignment, $lesson] = trainableAssignment();
    $question = Question::factory()->multipleChoice()->create([
        'lesson_id' => $lesson->id, 'position' => 2, 'max_attempts' => 3,
    ]);
    $first = QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'position' => 1]);
    QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'position' => 2]);

    watch($assignment, $lesson, 100);

    Livewire::actingAs($assignment->user)
        ->test('training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])
        ->set("selected.{$question->id}", [(string) $first->id])
        ->call('answer', $question->id);

    $attempt = QuestionAttempt::query()->where('question_id', $question->id)->firstOrFail();

    expect($attempt->selected_option_ids)->toBe([$first->id])
        ->and($attempt->is_correct)->toBeFalse();
});

it('refuses an empty submission without spending an attempt', function (): void {
    [$assignment, $lesson, $question] = trainableAssignment(maxAttempts: 2);

    watch($assignment, $lesson, 100);

    Livewire::actingAs($assignment->user)
        ->test('training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])
        ->call('answer', $question->id)
        ->assertHasErrors('assessment');

    expect(QuestionAttempt::query()->count())->toBe(0);
});

it('ignores a boolean left behind by a checkbox binding', function (): void {
    [$assignment, $lesson, $question] = trainableAssignment(maxAttempts: 2);

    watch($assignment, $lesson, 100);

    Livewire::actingAs($assignment->user)
        ->test('training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])
        ->set("selected.{$question->id}", false)
        ->call('answer', $question->id)
        ->assertHasErrors('assessment');

    expect(QuestionAttempt::query()->count())->toBe(0);
});

it('accepts a single-choice answer bound as a scalar', function (): void {
    [$assignment, $lesson, $question] = trainableAssignment();
    $correct = $question->options()->where('is_correct', true)->firstOrFail();

    Livewire::actingAs($assignment->user)
        ->test('training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])
        ->set("selected.{$question->id}", (string) $correct->id)
        ->call('answer', $question->id)
        ->assertHasNoErrors();

    expect(QuestionAttempt::query()->firstOrFail()->is_correct)->toBeTrue()
        ->and(QuestionType::SingleChoice)->toBe($question->type);
});

it('shows and accepts assessment questions immediately when the lesson opens', function (): void {
    [$assignment, $lesson, $question] = trainableAssignment();
    $correct = $question->options()->where('is_correct', true)->firstOrFail();

    Livewire::actingAs($assignment->user)
        ->test('training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])
        ->assertSee($question->prompt)
        ->set("selected.{$question->id}", (string) $correct->id)
        ->call('answer', $question->id)
        ->assertHasNoErrors();

    expect(QuestionAttempt::query()->firstOrFail()->is_correct)->toBeTrue();
});
