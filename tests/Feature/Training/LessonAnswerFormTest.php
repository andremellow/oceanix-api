<?php

use App\Enums\QuestionType;
use App\Enums\VideoStatus;
use App\Models\Question;
use App\Models\QuestionAttempt;
use App\Models\QuestionOption;
use Livewire\Livewire;

beforeEach(fn () => fakeCloudflarePlayback());

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

    watch($assignment, $lesson, 100);

    Livewire::actingAs($assignment->user)
        ->test('training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])
        ->set("selected.{$question->id}", (string) $correct->id)
        ->call('answer', $question->id)
        ->assertHasNoErrors();

    expect(QuestionAttempt::query()->firstOrFail()->is_correct)->toBeTrue()
        ->and(QuestionType::SingleChoice)->toBe($question->type);
});
