<?php

use App\Services\Compliance\EmployeeTrainingBoard;
use Livewire\Component;

new class extends Component
{
    public function with(EmployeeTrainingBoard $board): array
    {
        return ['board' => $board->build(auth()->user())];
    }
};
?>

<div class="space-y-7">
    <x-page-hero
        :kicker="__('ui.my_training')"
        :title="__('ui.my_training_title')"
        :description="__('ui.my_training_description')">
        <span class="status-pill status-pill--accent">{{ trans_choice('ui.open_items', $board['overdue']->count() + $board['due_soon']->count() + $board['in_progress']->count() + $board['upcoming']->count(), ['count' => $board['overdue']->count() + $board['due_soon']->count() + $board['in_progress']->count() + $board['upcoming']->count()]) }}</span>
    </x-page-hero>

    @foreach ([
        ['key' => 'overdue', 'title' => __('ui.overdue'), 'tone' => 'overdue', 'help' => __('ui.overdue_help')],
        ['key' => 'due_soon', 'title' => __('ui.due_soon'), 'tone' => 'due-soon', 'help' => __('ui.due_soon_help')],
        ['key' => 'in_progress', 'title' => __('ui.in_progress'), 'tone' => 'neutral', 'help' => __('ui.in_progress_help')],
        ['key' => 'upcoming', 'title' => __('ui.assigned'), 'tone' => 'neutral', 'help' => __('ui.assigned_help')],
    ] as $section)
        @if ($board[$section['key']]->isNotEmpty())
            <section>
                <div class="mb-4">
                    <h2 class="text-xl font-bold tracking-tight text-[#242a2f]">{{ $section['title'] }}</h2>
                    <p class="mt-1 text-sm text-[#6f797f]">{{ $section['help'] }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($board[$section['key']] as $assignment)
                        <x-training.assignment-card :$assignment :tone="$section['tone']" />
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach

    @if ($board['overdue']->isEmpty() && $board['due_soon']->isEmpty() && $board['in_progress']->isEmpty() && $board['upcoming']->isEmpty())
        <x-empty-state
            icon="check-badge"
            :title="__('ui.no_open_training')"
            :description="__('ui.no_open_training_help')" />
    @endif

    @if ($board['completed']->isNotEmpty())
        <section>
            <div class="mb-4">
                <h2 class="text-xl font-bold tracking-tight text-[#242a2f]">{{ __('ui.completed') }}</h2>
                <p class="mt-1 text-sm text-[#6f797f]">{{ __('ui.completed_help') }}</p>
            </div>
            <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7] shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th>{{ __('Course') }}</th>
                            <th>{{ __('Completed') }}</th>
                            <th>{{ __('Valid until') }}</th>
                            <th>{{ __('Certificate') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($board['completed'] as $assignment)
                            <tr class="border-t">
                                <td class="font-semibold text-[#262d33]">{{ $assignment->course->title }}</td>
                                <td class="text-[#5f6a71]">{{ $assignment->completed_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</td>
                                <td class="text-[#5f6a71]">{{ $assignment->expires_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? __('No expiry') }}</td>
                                <td>
                                    @if ($assignment->certificate)
                                        <a href="{{ route('certificates.download', ['certificate' => $assignment->certificate]) }}" class="font-semibold text-[#1c6b84] underline underline-offset-2">{{ $assignment->certificate->certificate_number }}</a>
                                        <a href="{{ route('certificates.verify', $assignment->certificate) }}" class="ml-2 text-xs text-[#8a9298] underline underline-offset-2">{{ __('ui.verify') }}</a>
                                    @else
                                        <span class="text-[#8a9298]">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
