<?php

use App\Actions\Training\AnswerQuestion;
use App\Actions\Training\StartAssignment;
use App\Actions\Training\StartLessonAttempt;
use App\Enums\AttemptStatus;
use App\Enums\ComplianceEventType;
use App\Enums\QuestionType;
use App\Exceptions\VideoProviderException;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use App\Services\Training\TrainingCompletionService;
use App\Services\Video\PlaybackAuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Component;

/**
 * The employee's lesson: watch, then answer.
 *
 * The assessment is gated on the server, never only in the markup — AnswerQuestion refuses
 * an answer whose lesson has not met its watch threshold, whatever the page shows.
 */
new class extends Component
{
    public UserTrainingAssignment $assignment;

    public Lesson $lesson;

    public ?string $playbackUrl = null;

    public ?string $posterUrl = null;

    public ?string $playbackError = null;

    /** @var array<int, list<int>> */
    public array $selected = [];

    /** @var array<int, array{correct: bool, attempts_left: int}> */
    public array $feedback = [];

    public ?string $lessonOutcome = null;

    public function mount(UserTrainingAssignment $assignment, Lesson $lesson, PlaybackAuthorizationService $playback): void
    {
        $this->authorize('execute', $assignment);

        abort_unless($lesson->course_version_id === $assignment->course_version_id, 404);

        $this->assignment = $assignment;
        $this->lesson = $lesson->load(['video', 'questions.options']);

        try {
            $authorization = $playback->authorize($assignment, $this->lesson);
            $this->playbackUrl = $authorization->playbackUrl;
            $this->posterUrl = $authorization->posterUrl;
        } catch (VideoProviderException) {
            // A provider outage or a missing remote asset must not expose an exception page
            // to the employee. The provider already logs the diagnostic details.
            $this->playbackError = __('ui.video_playback_failed_help');
        }

        // Seeded per question so Livewire binds a multiple-choice question to an array and
        // a single-choice one to a scalar, instead of inferring a boolean from an absent key.
        $this->selected = $this->lesson->questions
            ->mapWithKeys(fn (Question $question): array => [
                $question->id => $question->type === QuestionType::MultipleChoice ? [] : null,
            ])
            ->all();

        app(StartAssignment::class)->handle($assignment);

        app(ComplianceEventRecorder::class)->record(ComplianceEventType::AssignmentOpened, $assignment->user_id, [
            'assignment_id' => $assignment->id,
            'course_version_id' => $assignment->course_version_id,
            'lesson_id' => $this->lesson->id,
        ]);
    }

    public function answer(int $questionId, AnswerQuestion $action, TrainingCompletionService $completion): void
    {
        $this->authorize('execute', $this->assignment);

        $question = $this->lesson->questions()->findOrFail($questionId);

        if ($this->selectedOptionIds($questionId) === []) {
            $this->addError('assessment', __('ui.select_an_option'));

            return;
        }

        $courseAttempt = app(StartAssignment::class)->handle($this->assignment);
        $lessonAttempt = app(StartLessonAttempt::class)->handle($this->assignment, $courseAttempt, $this->lesson);

        try {
            $outcome = $action->handle(
                $this->assignment,
                $lessonAttempt,
                $question,
                $this->selectedOptionIds($questionId),
            );
        } catch (AuthorizationException $e) {
            $this->addError('assessment', $e->getMessage());

            return;
        }

        $this->feedback[$questionId] = [
            'correct' => $outcome['correct'],
            'attempts_left' => $outcome['attempts_left'],
        ];

        if ($outcome['lesson_failed']) {
            $this->lessonOutcome = 'failed';

            return;
        }

        $evaluated = $completion->evaluateLesson($this->assignment, $lessonAttempt->refresh());

        if ($evaluated->status === AttemptStatus::Passed) {
            $completion->evaluateCourse($this->assignment->refresh(), $courseAttempt->refresh());

            session()->flash('status', __('ui.lesson_passed', ['title' => $this->lesson->title]));

            $this->redirect(route('my-training.show', ['assignment' => $this->assignment]), navigate: true);

            return;
        }

        if ($evaluated->status === AttemptStatus::Failed) {
            $this->lessonOutcome = 'failed';
        }
    }

    /**
     * Single choice binds to a scalar and multiple choice to an array; both arrive here as
     * a list of ids.
     *
     * @return list<int>
     */
    private function selectedOptionIds(int $questionId): array
    {
        $selected = $this->selected[$questionId] ?? [];

        return collect(is_array($selected) ? $selected : [$selected])
            ->filter(fn ($value): bool => is_numeric($value) && (int) $value > 0)
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    public function with(): array
    {
        $progress = $this->assignment->lessonProgress()
            ->where('lesson_id', $this->lesson->id)
            ->first();

        $percentage = $progress?->percentage_watched ?? 0;

        return [
            'percentage' => $percentage,
            'unlocked' => $percentage >= $this->lesson->minimum_watch_percentage,
            'answeredIds' => $this->answeredQuestionIds(),
        ];
    }

    /** @return list<int> */
    private function answeredQuestionIds(): array
    {
        $attempt = $this->assignment->courseAttempts()
            ->where('status', AttemptStatus::InProgress->value)
            ->latest('attempt_number')
            ->first()
            ?->lessonAttempts()
            ->where('lesson_id', $this->lesson->id)
            ->where('status', AttemptStatus::InProgress->value)
            ->latest('attempt_number')
            ->first();

        if ($attempt === null) {
            return [];
        }

        return $attempt->questionAttempts()
            ->where('is_correct', true)
            ->pluck('question_id')
            ->all();
    }
};
?>

<div class="space-y-7">
    <x-status-message />
    <x-page-hero
        :kicker="$assignment->course->title"
        :title="$lesson->title"
        :description="$lesson->description">
        <span class="status-pill {{ $unlocked ? 'status-pill--positive' : 'status-pill--warning' }}">
            {{ $unlocked ? __('ui.assessment_unlocked') : __('ui.watch_to_unlock', ['percentage' => $lesson->minimum_watch_percentage]) }}
        </span>
        <flux:button :href="route('my-training.show', ['assignment' => $assignment])" wire:navigate variant="ghost" size="sm">{{ __('ui.back_to_assignment') }}</flux:button>
    </x-page-hero>

    @if ($playbackError !== null)
        <x-empty-state
            icon="exclamation-triangle"
            :title="__('ui.video_playback_failed')"
            :description="$playbackError" />
    @elseif ($lesson->video === null || ! $lesson->video->isPlayable())
        <x-empty-state
            icon="film"
            :title="__('ui.video_unavailable')"
            :description="__('ui.video_unavailable_help')" />
    @else
        <section
            class="overflow-hidden rounded-[22px] border border-[#dde3e7] bg-white shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]"
            x-data="lessonPlayer({
                playbackUrl: @js($playbackUrl),
                poster: @js($posterUrl),
                eventsUrl: @js(route('my-training.events', ['assignment' => $assignment, 'lesson' => $lesson])),
                playbackAuthUrl: @js(route('my-training.playback', ['assignment' => $assignment, 'lesson' => $lesson])),
                sessionId: @js(session()->getId()),
                unlocked: @js($unlocked),
            })"
        >
            <div class="bg-[#0f1a20]">
                <video x-ref="video" class="aspect-video w-full" controls playsinline></video>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4 p-4 sm:p-5">
                <div class="min-w-0 flex-1">
                    <div class="mb-1.5 flex items-center justify-between text-[11px] font-bold text-[#6f797f]">
                        <span>{{ __('ui.watched') }}</span>
                        <span x-text="`${Math.max(percentage, @js($percentage))}%`"></span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-[#e9edf0]">
                        <div class="h-full rounded-full bg-[#1c6b84] transition-all"
                            :style="`width: ${Math.max(percentage, @js($percentage))}%`"></div>
                    </div>
                </div>
                <p class="text-xs text-[#8a9298]">{{ __('ui.watch_threshold', ['percentage' => $lesson->minimum_watch_percentage]) }}</p>
            </div>

            <p class="px-4 pb-4 text-xs font-semibold text-[#9a6a1a] sm:px-5" x-show="blockedSeek" x-cloak>
                {{ __('ui.seek_blocked') }}
            </p>
            <p class="px-4 pb-4 text-xs font-semibold text-[#b23a3a] sm:px-5" x-show="error" x-text="error" x-cloak></p>
        </section>
    @endif

    <section class="detail-card">
        <span class="detail-card-icon"><flux:icon.clipboard-document-check class="size-5" /></span>
        <h2 class="detail-card-title">{{ __('Assessment') }}</h2>
        <p class="mt-1 text-sm text-[#6f797f]">
            {{ $unlocked ? __('ui.assessment_help') : __('ui.assessment_locked_help', ['percentage' => $lesson->minimum_watch_percentage]) }}
        </p>

        @error('assessment')
            <flux:callout variant="danger" :heading="$message" class="mt-4" />
        @enderror

        @if ($lessonOutcome === 'failed')
            <flux:callout variant="danger" :heading="__('ui.lesson_failed')" :text="__('ui.lesson_failed_help')" class="mt-4">
                <flux:button :href="route('my-training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])" variant="primary" class="mt-3">{{ __('ui.watch_again') }}</flux:button>
            </flux:callout>
        @elseif (! $unlocked)
            <div class="mt-5 rounded-[18px] border border-dashed border-[#d7dee3] p-8 text-center">
                <span class="mx-auto grid size-11 place-items-center rounded-2xl bg-[#eef3f6] text-[#7d878e]"><flux:icon.lock-closed class="size-5" /></span>
                <p class="mt-4 text-sm font-semibold text-[#5f6a71]">{{ __('ui.assessment_locked', ['percentage' => $percentage]) }}</p>
            </div>
        @else
            <div class="mt-5 space-y-4">
                @foreach ($lesson->questions as $question)
                    @php($answered = in_array($question->id, $answeredIds, true))
                    <div class="rounded-[18px] border border-[#e4e9ec] p-4 sm:p-5" wire:key="question-{{ $question->id }}">
                        <p class="font-semibold text-[#262d33]">{{ $question->position }}. {{ $question->prompt }}</p>
                        <p class="mt-1 text-xs text-[#8a9298]">
                            {{ $question->type->label() }}
                            · {{ trans_choice('ui.attempts_allowed', $question->max_attempts, ['count' => $question->max_attempts]) }}
                        </p>

                        <div class="mt-4 space-y-2">
                            @foreach ($question->options as $option)
                                @php($chosen = array_map('strval', is_array($selected[$question->id] ?? null) ? $selected[$question->id] : array_filter([$selected[$question->id] ?? null])))
                                <label class="role-option {{ in_array((string) $option->id, $chosen, true) ? 'is-selected' : '' }}">
                                    <input
                                        type="{{ $question->type === QuestionType::MultipleChoice ? 'checkbox' : 'radio' }}"
                                        wire:model="selected.{{ $question->id }}"
                                        value="{{ $option->id }}"
                                        @disabled($answered)
                                        class="mt-0.5 size-4 border-[#8e989f] text-[#1c6b84] focus:ring-[#3e8ba3]">
                                    <span class="text-sm text-[#262d33]">{{ $option->text }}</span>
                                </label>
                            @endforeach
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            @if ($answered)
                                <span class="status-pill status-pill--positive">{{ __('ui.answer_correct') }}</span>
                            @else
                                <flux:button wire:click="answer({{ $question->id }})" variant="primary" size="sm">{{ __('ui.submit_answer') }}</flux:button>
                            @endif

                            @if (isset($feedback[$question->id]) && ! $feedback[$question->id]['correct'])
                                <span class="status-pill status-pill--negative">{{ __('ui.answer_wrong') }}</span>
                                <span class="text-xs text-[#6f797f]">{{ trans_choice('ui.attempts_left', $feedback[$question->id]['attempts_left'], ['count' => $feedback[$question->id]['attempts_left']]) }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
