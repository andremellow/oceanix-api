@props(['course', 'version', 'lessons', 'progress', 'backUrl', 'assignment' => null, 'preview' => false, 'canExecute' => false, 'lessonUrls' => []])

<div class="space-y-7">
    <x-page-hero
        :kicker="$course->code"
        :title="$preview ? $version->title : $course->title"
        :description="$version->description ?: $course->description">
        <span class="status-pill {{ $preview ? 'status-pill--accent' : $assignment->status->pillModifier() }}" @if($preview) title="{{ __('Explore the learner view without assigning this course. Progress and answers are not recorded.') }}" @endif>{{ $preview ? __('Preview as learner') : $assignment->status->label() }}</span>
        <flux:button :href="$backUrl" wire:navigate variant="ghost" size="sm">{{ $preview ? __('Back to course') : __('ui.back_to_training') }}</flux:button>
    </x-page-hero>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="metric-card metric-card--teal">
            <span class="metric-icon"><flux:icon.chart-bar class="size-5" /></span>
            <p class="metric-label">{{ __('Progress') }}</p>
            <p class="metric-value">{{ ($preview ? 0 : $assignment->progressPercentage()) }}%</p>
            <p class="metric-detail">{{ trans_choice('ui.required_lessons', $version->requiredLessonCount(), ['count' => $version->requiredLessonCount()]) }}</p>
        </div>
        <div class="metric-card {{ (! $preview && $assignment->isOverdue()) ? 'metric-card--rose' : 'metric-card--amber' }}">
            <span class="metric-icon"><flux:icon.calendar-days class="size-5" /></span>
            <p class="metric-label">{{ __('Due date') }}</p>
            <p class="metric-value metric-value--text">{{ $preview ? __('Not applicable') : ($assignment->due_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? __('No deadline')) }}</p>
            <p class="metric-detail">
                {{ (! $preview && $assignment->isOverdue())
                    ? trans_choice('ui.days_overdue', $assignment->daysOverdue(), ['count' => $assignment->daysOverdue()])
                    : ($preview ? __('No assignment is created') : __('ui.on_schedule')) }}
            </p>
        </div>
        <div class="metric-card metric-card--slate">
            <span class="metric-icon"><flux:icon.document-text class="size-5" /></span>
            <p class="metric-label">{{ __('Content version') }}</p>
            <p class="metric-value metric-value--text">{{ __('Version :number', ['number' => $version->version_number]) }}</p>
            <p class="metric-detail">{{ $preview ? $version->status->label() : __('ui.version_frozen') }}</p>
        </div>
        <div class="metric-card metric-card--violet">
            <span class="metric-icon"><flux:icon.identification class="size-5" /></span>
            <p class="metric-label">{{ __('Assigned by') }}</p>
            <p class="metric-value metric-value--text">{{ $preview ? __('Not assigned') : $assignment->origin_type->label() }}</p>
            <p class="metric-detail">{{ $preview ? __('Preview only') : $assignment->assigned_at->locale(app()->getLocale())->translatedFormat('M j, Y') }}</p>
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
                            {{ trans_choice('ui.questions_count', $lesson->questions->count(), ['count' => $lesson->questions->count()]) }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-bold text-[#6f797f]">{{ $lessonProgress?->percentage_watched ?? 0 }}%</span>
                        <span class="status-pill {{ $lessonProgress?->completed_at ? 'status-pill--positive' : 'status-pill--neutral' }}">
                            {{ $lessonProgress?->completed_at ? __('Completed') : __('Not started') }}
                        </span>
                        @if($preview || $canExecute)
                            <flux:button :href="$preview ? $lessonUrls[$lesson->id] : route('my-training.lesson', ['assignment' => $assignment, 'lesson' => $lesson])" wire:navigate variant="primary" size="sm">
                                {{ $lessonProgress?->completed_at ? __('Review') : (($lessonProgress?->percentage_watched ?? 0) > 0 ? __('Continue') : __('Start')) }}
                            </flux:button>
                        @endif
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

</div>
