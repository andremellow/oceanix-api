<?php

use App\Models\UserTrainingAssignment;
use Livewire\Component;

new class extends Component
{
    public UserTrainingAssignment $assignment;

    public function mount(UserTrainingAssignment $assignment): void
    {
        // Re-authorize on every hydration: revoking an assignment must take effect even on
        // a page that was already open.
        $this->authorize('view', $assignment);

        $this->assignment = $assignment->load([
            'course', 'courseVersion.lessons.video', 'courseVersion.lessons.questions',
            'courseVersion.moduleCompositions.moduleVersion.video',
            'courseVersion.moduleCompositions.moduleVersion.questions', 'certificate',
        ]);
    }

    public function with(): array
    {
        $version = $this->assignment->courseVersion;
        $lessons = $version->moduleCompositions->isNotEmpty()
            ? $version->moduleCompositions->pluck('moduleVersion')->filter()->values()
            : $version->lessons;

        return [
            'lessons' => $lessons,
            'progress' => $this->assignment->lessonProgress()->get()->keyBy('lesson_id'),
            'events' => $this->assignment->complianceEvents()
                ->latest('occurred_at')
                ->limit(20)
                ->get(),
        ];
    }
};
?>

<div class="space-y-7">
    <x-page-hero
        :kicker="$assignment->course->code"
        :title="$assignment->course->title"
        :description="$assignment->courseVersion->description ?: $assignment->course->description">
        <span class="status-pill {{ $assignment->status->pillModifier() }}">{{ $assignment->status->label() }}</span>
        <flux:button :href="route('my-training')" wire:navigate variant="ghost" size="sm">{{ __('ui.back_to_training') }}</flux:button>
    </x-page-hero>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="metric-card metric-card--teal">
            <span class="metric-icon"><flux:icon.chart-bar class="size-5" /></span>
            <p class="metric-label">{{ __('Progress') }}</p>
            <p class="metric-value">{{ $assignment->progressPercentage() }}%</p>
            <p class="metric-detail">{{ trans_choice('ui.required_lessons', $assignment->courseVersion->requiredLessonCount(), ['count' => $assignment->courseVersion->requiredLessonCount()]) }}</p>
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
        <div class="metric-card metric-card--slate">
            <span class="metric-icon"><flux:icon.document-text class="size-5" /></span>
            <p class="metric-label">{{ __('Content version') }}</p>
            <p class="metric-value metric-value--text">{{ __('Version :number', ['number' => $assignment->courseVersion->version_number]) }}</p>
            <p class="metric-detail">{{ __('ui.version_frozen') }}</p>
        </div>
        <div class="metric-card metric-card--violet">
            <span class="metric-icon"><flux:icon.identification class="size-5" /></span>
            <p class="metric-label">{{ __('Assigned by') }}</p>
            <p class="metric-value metric-value--text">{{ $assignment->origin_type->label() }}</p>
            <p class="metric-detail">{{ $assignment->assigned_at->locale(app()->getLocale())->translatedFormat('M j, Y') }}</p>
        </div>
    </div>

    <section class="detail-card">
        <div class="flex items-start justify-between gap-4">
            <div>
                <span class="detail-card-icon"><flux:icon.queue-list class="size-5" /></span>
                <h2 class="detail-card-title">{{ __('Lessons') }}</h2>
                <p class="mt-1 text-sm text-[#6f797f]">{{ __('ui.lessons_help') }}</p>
            </div>
        </div>

        <div class="mt-5 space-y-3">
            @forelse ($lessons as $lesson)
                @php($lessonProgress = $progress->get($lesson->id))
                <div class="flex flex-col gap-3 rounded-[18px] border border-[#e4e9ec] bg-[#f8fafb] p-4 sm:flex-row sm:items-center">
                    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-white text-sm font-bold text-[#1c6b84] shadow-sm ring-1 ring-[#dfe7eb]">{{ $loop->iteration }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-[#262d33]">{{ $lesson->title }}</p>
                        <p class="mt-0.5 text-xs text-[#8a9298]">
                            {{ $lesson->video?->formattedDuration() ?? '—' }}
                            · {{ trans_choice('ui.questions_count', $lesson->questions->count(), ['count' => $lesson->questions->count()]) }}
                            · {{ __('ui.watch_threshold', ['percentage' => $lesson->minimum_watch_percentage]) }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-bold text-[#6f797f]">{{ $lessonProgress?->percentage_watched ?? 0 }}%</span>
                        <span class="status-pill {{ $lessonProgress?->completed_at ? 'status-pill--positive' : 'status-pill--neutral' }}">
                            {{ $lessonProgress?->completed_at ? __('Completed') : __('Not started') }}
                        </span>
                        @can('execute', $assignment)
                            <flux:button :href="route('my-training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])" wire:navigate variant="primary" size="sm">
                                {{ $lessonProgress?->completed_at ? __('Review') : (($lessonProgress?->percentage_watched ?? 0) > 0 ? __('Continue') : __('Start')) }}
                            </flux:button>
                        @endcan
                    </div>
                </div>
            @empty
                <x-empty-state
                    icon="film"
                    :title="__('ui.no_lessons')"
                    :description="__('ui.no_lessons_help')" />
            @endforelse
        </div>
    </section>

    <section class="detail-card">
        <span class="detail-card-icon"><flux:icon.clock class="size-5" /></span>
        <h2 class="detail-card-title">{{ __('Activity') }}</h2>
        <p class="mt-1 text-sm text-[#6f797f]">{{ __('ui.activity_help') }}</p>

        <div class="mt-5">
            @forelse ($events as $event)
                <div class="flex items-start gap-3 border-b border-[#eef1f4] py-3 last:border-0">
                    <span class="mt-1.5 size-2 shrink-0 rounded-full bg-[#9fb3bc]"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-[#262d33]">{{ $event->event_type->label() }}</p>
                        <p class="text-xs text-[#8a9298]">{{ $event->occurred_at->locale(app()->getLocale())->translatedFormat('M j, Y · H:i') }}</p>
                    </div>
                </div>
            @empty
                <x-empty-state
                    icon="clock"
                    :title="__('ui.no_activity')"
                    :description="__('ui.no_activity_help')" />
            @endforelse
        </div>
    </section>
</div>
