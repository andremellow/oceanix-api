<?php

use App\Enums\Permission;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\AssignmentEvidence;
use Livewire\Component;

/**
 * The auditor's view of one obligation: what was watched, how many times, what was answered,
 * and every event behind it. See docs/product-spec.md §15.
 */
new class extends Component
{
    public UserTrainingAssignment $assignment;

    public function mount(UserTrainingAssignment $assignment): void
    {
        $this->authorize('view', $assignment);

        $this->assignment = $assignment->load([
            'user.departments', 'user.jobFunctions', 'course',
            'courseVersion.lessons.video', 'certificate', 'trainingRequirement',
        ]);
    }

    public function with(AssignmentEvidence $evidence): array
    {
        return [
            'watchMaps' => $this->assignment->courseVersion->lessons
                ->mapWithKeys(fn ($lesson): array => [$lesson->id => $evidence->watchMap($this->assignment, $lesson)]),
            'attempts' => $evidence->attempts($this->assignment),
            'devices' => $evidence->devices($this->assignment),
            'timeline' => auth()->user()->can(Permission::ComplianceEventsView->value)
                ? $evidence->timeline($this->assignment)
                : collect(),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="$assignment->course->code"
        :title="$assignment->user->name"
        :description="$assignment->course->title">
        <span class="status-pill {{ $assignment->status->pillModifier() }}">{{ $assignment->status->label() }}</span>
        <flux:button :href="route('assignments.index')" wire:navigate variant="ghost" size="sm">{{ __('ui.back_to_assignments') }}</flux:button>
    </x-page-hero>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="metric-card metric-card--teal">
            <span class="metric-icon"><flux:icon.identification class="size-5" /></span>
            <p class="metric-label">{{ __('Origin') }}</p>
            <p class="metric-value metric-value--text">{{ $assignment->origin_type->label() }}</p>
            <p class="metric-detail">{{ $assignment->trainingRequirement?->name ?? __('ui.no_requirement') }}</p>
        </div>
        <div class="metric-card metric-card--slate">
            <span class="metric-icon"><flux:icon.document-text class="size-5" /></span>
            <p class="metric-label">{{ __('Content version') }}</p>
            <p class="metric-value metric-value--text">{{ __('Version :number', ['number' => $assignment->courseVersion->version_number]) }}</p>
            <p class="metric-detail">{{ __('ui.version_frozen') }}</p>
        </div>
        <div class="metric-card {{ $assignment->isOverdue() ? 'metric-card--rose' : 'metric-card--amber' }}">
            <span class="metric-icon"><flux:icon.calendar-days class="size-5" /></span>
            <p class="metric-label">{{ __('Due date') }}</p>
            <p class="metric-value metric-value--text">{{ $assignment->due_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? __('No deadline') }}</p>
            <p class="metric-detail">
                {{ $assignment->isOverdue()
                    ? trans_choice('ui.days_overdue', $assignment->daysOverdue(), ['count' => $assignment->daysOverdue()])
                    : __('ui.on_schedule') }}
            </p>
        </div>
        <div class="metric-card metric-card--violet">
            <span class="metric-icon"><flux:icon.document-check class="size-5" /></span>
            <p class="metric-label">{{ __('Certificate') }}</p>
            <p class="metric-value metric-value--text">{{ $assignment->certificate?->certificate_number ?? '—' }}</p>
            <p class="metric-detail">{{ $assignment->completed_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? __('ui.not_completed') }}</p>
        </div>
    </div>

    {{-- Watch coverage --}}
    <section class="detail-card">
        <span class="detail-card-icon"><flux:icon.film class="size-5" /></span>
        <h2 class="detail-card-title">{{ __('ui.watch_evidence') }}</h2>
        <p class="mt-1 text-sm text-[#6f797f]">{{ __('ui.watch_evidence_help') }}</p>

        <div class="mt-5 space-y-5">
            @foreach ($assignment->courseVersion->lessons as $lesson)
                @php($map = $watchMaps[$lesson->id])
                <div wire:key="watch-{{ $lesson->id }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-bold text-[#262d33]">{{ $lesson->position }}. {{ $lesson->title }}</p>
                        <p class="text-xs text-[#8a9298]">
                            {{ __('ui.covered_of', ['covered' => $map['covered'], 'duration' => $map['duration']]) }}
                            · <span class="font-bold {{ $map['percentage'] >= $lesson->minimum_watch_percentage ? 'text-[#2f7d4f]' : 'text-[#9a6a1a]' }}">{{ $map['percentage'] }}%</span>
                            · {{ __('ui.threshold_short', ['percentage' => $lesson->minimum_watch_percentage]) }}
                        </p>
                    </div>

                    @if ($map['buckets'] === [])
                        <p class="mt-2 text-xs text-[#8a9298]">{{ __('ui.no_playback_recorded') }}</p>
                    @else
                        {{-- Each block is a slice of the video; the darker it is, the more times it was watched. --}}
                        <div class="mt-2 flex gap-px overflow-hidden rounded-full" role="img"
                            aria-label="{{ __('ui.coverage_aria', ['percentage' => $map['percentage']]) }}">
                            @foreach ($map['buckets'] as $bucket)
                                <span class="h-3 flex-1"
                                    style="background: {{ match (true) {
                                        $bucket['times'] === 0 => '#e9edf0',
                                        $bucket['times'] === 1 => '#7fb3c4',
                                        $bucket['times'] === 2 => '#3d8ba4',
                                        default => '#16505f',
                                    } }}"
                                    title="{{ gmdate('i:s', $bucket['from']) }}–{{ gmdate('i:s', $bucket['to']) }}: {{ trans_choice('ui.times_watched', $bucket['times'], ['count' => $bucket['times']]) }}"></span>
                            @endforeach
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-4 text-[11px] text-[#8a9298]">
                            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-[#e9edf0]"></span>{{ __('ui.never_watched') }}</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-[#7fb3c4]"></span>{{ __('ui.watched_once') }}</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-[#3d8ba4]"></span>{{ __('ui.watched_twice') }}</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-[#16505f]"></span>{{ __('ui.watched_more') }}</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    {{-- Attempts --}}
    <section class="detail-card">
        <span class="detail-card-icon"><flux:icon.clipboard-document-check class="size-5" /></span>
        <h2 class="detail-card-title">{{ __('ui.attempt_history') }}</h2>
        <p class="mt-1 text-sm text-[#6f797f]">{{ __('ui.attempt_history_help') }}</p>

        <div class="mt-5 space-y-4">
            @forelse ($attempts as $courseAttempt)
                <div class="rounded-[18px] border border-[#e4e9ec] p-4 sm:p-5" wire:key="attempt-{{ $courseAttempt->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm font-bold text-[#262d33]">{{ __('ui.course_attempt', ['number' => $courseAttempt->attempt_number]) }}</p>
                        <div class="flex items-center gap-2">
                            @if ($courseAttempt->score !== null)
                                <span class="text-xs font-bold text-[#6f797f]">{{ $courseAttempt->score }}%</span>
                            @endif
                            <span class="status-pill {{ $courseAttempt->status->isFinished() ? 'status-pill--positive' : 'status-pill--accent' }}">{{ $courseAttempt->status->label() }}</span>
                        </div>
                    </div>

                    <div class="mt-4 space-y-3">
                        @foreach ($courseAttempt->lessonAttempts as $lessonAttempt)
                            <div class="rounded-[14px] bg-[#f8fafb] p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm font-semibold text-[#3d464c]">
                                        {{ $lessonAttempt->lesson->title }}
                                        <span class="text-xs text-[#8a9298]">· {{ __('ui.attempt_number', ['number' => $lessonAttempt->attempt_number]) }}</span>
                                    </p>
                                    <span class="status-pill {{ match ($lessonAttempt->status->value) {
                                        'passed' => 'status-pill--positive',
                                        'failed' => 'status-pill--negative',
                                        default => 'status-pill--neutral',
                                    } }}">{{ $lessonAttempt->status->label() }}</span>
                                </div>

                                @if ($lessonAttempt->questionAttempts->isNotEmpty())
                                    <ul class="mt-2 space-y-1">
                                        @foreach ($lessonAttempt->questionAttempts as $questionAttempt)
                                            <li class="flex flex-wrap items-baseline gap-2 text-xs">
                                                <span class="{{ $questionAttempt->is_correct ? 'text-[#2f7d4f]' : 'text-[#b23a3a]' }} font-bold">
                                                    {{ $questionAttempt->is_correct ? '✓' : '✗' }}
                                                </span>
                                                <span class="text-[#5f6a71]">{{ $questionAttempt->question->prompt }}</span>
                                                <span class="text-[#8a9298]">
                                                    {{ __('ui.try_number', ['number' => $questionAttempt->attempt_number]) }}
                                                    · {{ $questionAttempt->answered_at->locale(app()->getLocale())->translatedFormat('M j, H:i') }}
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <x-empty-state icon="clipboard-document-check" :title="__('ui.no_attempts')" :description="__('ui.no_attempts_help')" />
            @endforelse
        </div>
    </section>

    {{-- Raw trail --}}
    @can(App\Enums\Permission::ComplianceEventsView->value)
        <section class="detail-card">
            <span class="detail-card-icon"><flux:icon.shield-check class="size-5" /></span>
            <h2 class="detail-card-title">{{ __('ui.evidence_trail') }}</h2>
            <p class="mt-1 text-sm text-[#6f797f]">{{ __('ui.evidence_trail_help') }}</p>

            @if ($devices->isNotEmpty())
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($devices as $device)
                        <span class="status-pill status-pill--neutral">
                            {{ trans_choice('ui.device_events', (int) $device->events, ['count' => $device->events]) }}
                        </span>
                    @endforeach
                </div>
            @endif

            <div class="mt-5 overflow-x-auto rounded-[16px] border border-[#e4e9ec]">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th>{{ __('ui.event') }}</th>
                            <th>{{ __('Lessons') }}</th>
                            <th class="text-right">{{ __('ui.position') }}</th>
                            <th>{{ __('ui.claimed_at') }}</th>
                            <th>{{ __('ui.received_at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($timeline as $event)
                            <tr class="border-t">
                                <td class="font-semibold text-[#262d33]">{{ $event->event_type->label() }}</td>
                                <td class="text-[#5f6a71]">{{ $event->lesson?->title ?? '—' }}</td>
                                <td class="text-right text-[#5f6a71]">{{ $event->position_seconds !== null ? gmdate('i:s', $event->position_seconds) : '—' }}</td>
                                <td class="text-[#5f6a71]">{{ $event->occurred_at->locale(app()->getLocale())->translatedFormat('M j, H:i:s') }}</td>
                                <td class="{{ $event->hasClockSkew() ? 'font-bold text-[#9a6a1a]' : 'text-[#8a9298]' }}">
                                    {{ $event->received_at->locale(app()->getLocale())->translatedFormat('M j, H:i:s') }}
                                    @if ($event->hasClockSkew())
                                        <span class="block text-[10px]">{{ __('ui.clock_skew') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-[#8a9298]">{{ __('ui.no_activity') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endcan
</div>
