@props(['assignment', 'tone' => 'neutral'])

@php
    $accent = match ($tone) {
        'overdue' => ['border' => '#f0cfcf', 'chip' => 'status-pill--negative'],
        'due-soon' => ['border' => '#f2e0bd', 'chip' => 'status-pill--warning'],
        default => ['border' => '#dde3e7', 'chip' => $assignment->status->pillModifier()],
    };
    $progress = $assignment->progressPercentage();
@endphp

<a href="{{ route('my-training.show', $assignment) }}" wire:navigate
   class="saas-feature-card group flex flex-col"
   style="border-color: {{ $accent['border'] }}">
    <div class="flex items-start justify-between gap-3">
        <span class="saas-feature-icon bg-[#e4f0f5] text-[#1c6b84]"><flux:icon.play-circle class="size-5" /></span>
        <span class="status-pill {{ $accent['chip'] }}">{{ $assignment->status->label() }}</span>
    </div>

    <span class="mt-5 block text-base font-bold text-[#262d33]">{{ $assignment->course->title }}</span>
    <span class="mt-1.5 block text-sm leading-5 text-[#778188]">
        {{ __('Version :number', ['number' => $assignment->courseVersion->version_number]) }}
        @if ($assignment->courseVersion->estimatedMinutes() > 0)
            · {{ __(':count min', ['count' => $assignment->courseVersion->estimatedMinutes()]) }}
        @endif
    </span>

    <div class="mt-5">
        <div class="mb-1.5 flex items-center justify-between text-[11px] font-bold text-[#6f797f]">
            <span>{{ __('Progress') }}</span>
            <span>{{ $progress }}%</span>
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-[#e9edf0]">
            <div class="h-full rounded-full bg-[#1c6b84]" style="width: {{ $progress }}%"></div>
        </div>
    </div>

    <div class="mt-5 flex items-center justify-between gap-3 border-t border-[#eef1f4] pt-4">
        <span class="text-xs {{ $assignment->isOverdue() ? 'font-bold text-[#b23a3a]' : 'text-[#8a9298]' }}">
            @if ($assignment->due_at === null)
                {{ __('No deadline') }}
            @elseif ($assignment->isOverdue())
                {{ trans_choice('ui.days_overdue', $assignment->daysOverdue(), ['count' => $assignment->daysOverdue()]) }}
            @else
                {{ __('Due :date', ['date' => $assignment->due_at->locale(app()->getLocale())->translatedFormat('M j, Y')]) }}
            @endif
        </span>
        <span class="inline-flex items-center gap-1 text-xs font-bold text-[#1c6b84]">
            {{ $progress > 0 ? __('Continue') : __('Start') }} <span class="transition group-hover:translate-x-0.5" aria-hidden="true">→</span>
        </span>
    </div>
</a>
