<?php

use App\Actions\Training\AnswerQuestion;
use App\Actions\Training\StartAssignment;
use App\Actions\Training\StartLessonAttempt;
use App\Enums\AssignmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\ComplianceEventType;
use App\Enums\QuestionType;
use App\Models\Certificate;
use App\Models\ComplianceEvent;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Video;
use App\Services\Compliance\ComplianceEventRecorder;
use App\Services\Training\LessonProgressProjector;
use App\Services\Training\TrainingCompletionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

it('credits progress for playback that happens in real time', function (): void {
    [$assignment, $lesson] = trainableAssignment();

    watch($assignment, $lesson, 90);

    expect($assignment->lessonProgress()->first()->percentage_watched)->toBeGreaterThanOrEqual(90);
});

it('refuses to credit a position that jumps further than time allows', function (): void {
    [$assignment, $lesson] = trainableAssignment();
    $recorder = app(ComplianceEventRecorder::class);

    $recorder->record(ComplianceEventType::VideoPlayed, $assignment->user_id, [
        'assignment_id' => $assignment->id, 'lesson_id' => $lesson->id, 'position_seconds' => 0,
    ]);

    // One second later the client claims to be at the end of a 100 second video.
    Carbon::setTestNow(Carbon::now()->addSecond());
    $recorder->record(ComplianceEventType::VideoProgressed, $assignment->user_id, [
        'assignment_id' => $assignment->id, 'lesson_id' => $lesson->id, 'position_seconds' => 100,
    ]);

    $progress = app(LessonProgressProjector::class)->project($assignment, $lesson);

    expect($progress->percentage_watched)->toBe(0)
        // The attempt is still evidence: it is recorded, just not credited.
        ->and(ComplianceEvent::query()->where('lesson_id', $lesson->id)->count())->toBe(2);
});

it('locks the assessment until the watch threshold is met', function (): void {
    [$assignment, $lesson, $question] = trainableAssignment();

    watch($assignment, $lesson, 40);

    $courseAttempt = app(StartAssignment::class)->handle($assignment);
    $lessonAttempt = app(StartLessonAttempt::class)->handle($assignment, $courseAttempt, $lesson);

    expect(fn () => app(AnswerQuestion::class)->handle(
        $assignment, $lessonAttempt, $question, $question->correctOptionIds()
    ))->toThrow(AuthorizationException::class);
});

it('completes the course and issues a certificate when the lesson is passed', function (): void {
    [$assignment, $lesson, $question] = trainableAssignment();

    watch($assignment, $lesson, 100);

    $courseAttempt = app(StartAssignment::class)->handle($assignment);
    $lessonAttempt = app(StartLessonAttempt::class)->handle($assignment, $courseAttempt, $lesson);

    $outcome = app(AnswerQuestion::class)->handle($assignment, $lessonAttempt, $question, $question->correctOptionIds());
    expect($outcome['correct'])->toBeTrue();

    $completion = app(TrainingCompletionService::class);
    $completion->evaluateLesson($assignment, $lessonAttempt->refresh());
    $completion->evaluateCourse($assignment->refresh(), $courseAttempt->refresh());

    $assignment->refresh();

    expect($assignment->status)->toBe(AssignmentStatus::Completed)
        ->and($assignment->completed_at)->not->toBeNull()
        ->and($assignment->certificate)->not->toBeNull()
        ->and(ComplianceEvent::query()->where('event_type', ComplianceEventType::CourseCompleted->value)->count())->toBe(1)
        ->and(ComplianceEvent::query()->where('event_type', ComplianceEventType::CertificateIssued->value)->count())->toBe(1);
});

it('issues exactly one certificate however often completion runs', function (): void {
    [$assignment, $lesson, $question] = trainableAssignment();
    watch($assignment, $lesson, 100);

    $courseAttempt = app(StartAssignment::class)->handle($assignment);
    $lessonAttempt = app(StartLessonAttempt::class)->handle($assignment, $courseAttempt, $lesson);
    app(AnswerQuestion::class)->handle($assignment, $lessonAttempt, $question, $question->correctOptionIds());

    $completion = app(TrainingCompletionService::class);
    $completion->evaluateLesson($assignment, $lessonAttempt->refresh());
    $completion->evaluateCourse($assignment->refresh(), $courseAttempt->refresh());
    $completion->evaluateCourse($assignment->refresh(), $courseAttempt->refresh());

    expect(Certificate::query()->count())->toBe(1);
});

it('fails the lesson and forces a rewatch when attempts run out', function (): void {
    [$assignment, $lesson, $question] = trainableAssignment(maxAttempts: 2);
    watch($assignment, $lesson, 100);

    $wrong = $question->options()->where('is_correct', false)->pluck('id')->all();
    $courseAttempt = app(StartAssignment::class)->handle($assignment);
    $lessonAttempt = app(StartLessonAttempt::class)->handle($assignment, $courseAttempt, $lesson);

    $first = app(AnswerQuestion::class)->handle($assignment, $lessonAttempt, $question, $wrong);
    expect($first['lesson_failed'])->toBeFalse()
        ->and($first['attempts_left'])->toBe(1);

    $second = app(AnswerQuestion::class)->handle($assignment, $lessonAttempt, $question, $wrong);

    expect($second['lesson_failed'])->toBeTrue()
        ->and($lessonAttempt->fresh()->status)->toBe(AttemptStatus::Failed)
        // The watch threshold resets, so the video has to be watched again.
        ->and($assignment->lessonProgress()->first()->percentage_watched)->toBe(0)
        ->and($assignment->fresh()->status)->not->toBe(AssignmentStatus::Completed);
});

it('keeps every wrong answer in the history', function (): void {
    [$assignment, $lesson, $question] = trainableAssignment(maxAttempts: 3);
    watch($assignment, $lesson, 100);

    $wrong = $question->options()->where('is_correct', false)->pluck('id')->all();
    $courseAttempt = app(StartAssignment::class)->handle($assignment);
    $lessonAttempt = app(StartLessonAttempt::class)->handle($assignment, $courseAttempt, $lesson);

    app(AnswerQuestion::class)->handle($assignment, $lessonAttempt, $question, $wrong);
    app(AnswerQuestion::class)->handle($assignment, $lessonAttempt, $question, $question->correctOptionIds());

    $attempts = $lessonAttempt->questionAttempts()->orderBy('attempt_number')->get();

    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->is_correct)->toBeFalse()
        ->and($attempts[1]->is_correct)->toBeTrue();
});

it('refuses to answer more times than the question allows', function (): void {
    [$assignment, $lesson, $question] = trainableAssignment(maxAttempts: 1);
    watch($assignment, $lesson, 100);

    $courseAttempt = app(StartAssignment::class)->handle($assignment);
    $lessonAttempt = app(StartLessonAttempt::class)->handle($assignment, $courseAttempt, $lesson);

    app(AnswerQuestion::class)->handle($assignment, $lessonAttempt, $question, $question->correctOptionIds());

    expect(fn () => app(AnswerQuestion::class)->handle(
        $assignment, $lessonAttempt, $question, $question->correctOptionIds()
    ))->toThrow(AuthorizationException::class);
});

it('grades multiple choice only when the full set matches', function (): void {
    [$assignment, $lesson] = trainableAssignment();
    $question = Question::factory()->create([
        'lesson_id' => $lesson->id,
        'type' => QuestionType::MultipleChoice,
        'max_attempts' => 5,
        'position' => 2,
    ]);
    $a = QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'position' => 1]);
    $b = QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'position' => 2]);
    QuestionOption::factory()->create(['question_id' => $question->id, 'position' => 3]);

    watch($assignment, $lesson, 100);
    $courseAttempt = app(StartAssignment::class)->handle($assignment);
    $lessonAttempt = app(StartLessonAttempt::class)->handle($assignment, $courseAttempt, $lesson);
    $answer = app(AnswerQuestion::class);

    expect($answer->handle($assignment, $lessonAttempt, $question, [$a->id])['correct'])->toBeFalse()
        ->and($answer->handle($assignment, $lessonAttempt, $question, [$a->id, $b->id])['correct'])->toBeTrue();
});

it('ignores option ids that belong to another question', function (): void {
    [$assignment, $lesson, $question] = trainableAssignment(maxAttempts: 3);
    $foreign = QuestionOption::factory()->correct()->create();

    watch($assignment, $lesson, 100);
    $courseAttempt = app(StartAssignment::class)->handle($assignment);
    $lessonAttempt = app(StartLessonAttempt::class)->handle($assignment, $courseAttempt, $lesson);

    $outcome = app(AnswerQuestion::class)->handle($assignment, $lessonAttempt, $question, [$foreign->id]);

    expect($outcome['correct'])->toBeFalse()
        ->and($lessonAttempt->questionAttempts()->first()->selected_option_ids)->toBe([]);
});

it('starts a new numbered attempt instead of reopening a finished one', function (): void {
    [$assignment, $lesson, $question] = trainableAssignment(maxAttempts: 1);
    watch($assignment, $lesson, 100);

    $courseAttempt = app(StartAssignment::class)->handle($assignment);
    $first = app(StartLessonAttempt::class)->handle($assignment, $courseAttempt, $lesson);
    app(AnswerQuestion::class)->handle($assignment, $first, $question, $question->options()->where('is_correct', false)->pluck('id')->all());

    watch($assignment->fresh(), $lesson, 100);
    $second = app(StartLessonAttempt::class)->handle($assignment, $courseAttempt, $lesson);

    expect($second->id)->not->toBe($first->id)
        ->and($second->attempt_number)->toBe(2)
        ->and($first->fresh()->status)->toBe(AttemptStatus::Failed);
});

it('counts a rewatched stretch once, not twice', function (): void {
    [$assignment, $lesson] = trainableAssignment();
    $recorder = app(ComplianceEventRecorder::class);
    $clock = Carbon::now();

    // Watch the first half, rewind, watch it again. Half the video has been seen.
    foreach ([0, 10, 20, 30, 40, 50, 0, 10, 20, 30, 40, 50] as $position) {
        $clock = $clock->copy()->addSeconds(10);
        Carbon::setTestNow($clock);

        $recorder->record(ComplianceEventType::VideoProgressed, $assignment->user_id, [
            'uuid' => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'lesson_id' => $lesson->id,
            'position_seconds' => $position,
        ]);
    }

    $progress = app(LessonProgressProjector::class)->project($assignment, $lesson);

    expect($progress->percentage_watched)->toBe(50)
        ->and($progress->watched_seconds)->toBe(50);
});

it('unions overlapping stretches instead of adding them up', function (): void {
    [$assignment, $lesson] = trainableAssignment();
    $recorder = app(ComplianceEventRecorder::class);
    $clock = Carbon::now();

    // 0→40, then back to 20 and on to 60: 60 distinct seconds, not 80.
    foreach ([0, 20, 40, 20, 40, 60] as $position) {
        $clock = $clock->copy()->addSeconds(25);
        Carbon::setTestNow($clock);

        $recorder->record(ComplianceEventType::VideoProgressed, $assignment->user_id, [
            'uuid' => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'lesson_id' => $lesson->id,
            'position_seconds' => $position,
        ]);
    }

    expect(app(LessonProgressProjector::class)->project($assignment, $lesson)->watched_seconds)->toBe(60);
});

it('reaches the threshold from a single honest watch', function (): void {
    [$assignment, $lesson] = trainableAssignment();

    watch($assignment, $lesson, 95);

    expect($assignment->lessonProgress()->first()->percentage_watched)->toBeGreaterThanOrEqual(90);
});
