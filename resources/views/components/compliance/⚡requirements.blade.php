<?php

use App\Models\TrainingRequirement;
use App\Services\Requirements\RequirementEligibilityService;
use Livewire\Component;

new class extends Component
{
    public function with(RequirementEligibilityService $eligibility): array
    {
        $requirements = TrainingRequirement::query()
            ->with(['course', 'targets.department', 'targets.jobFunction'])
            ->withCount('assignments')
            ->orderBy('name')
            ->get();

        return [
            'requirements' => $requirements,
            // "In scope today" is a different question from "already owes this training".
            'inScope' => $requirements->mapWithKeys(fn (TrainingRequirement $requirement): array => [
                $requirement->id => $eligibility->count($requirement),
            ]),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.compliance')"
        :title="__('Training requirements')"
        :description="__('ui.requirements_page_description')">
        @can('create', App\Models\TrainingRequirement::class)
            <flux:button variant="primary" class="admin-primary-action" disabled>{{ __('New requirement') }}</flux:button>
        @endcan
    </x-page-hero>

    @if ($requirements->isEmpty())
        <x-empty-state
            icon="clipboard-document-check"
            :title="__('ui.no_requirements')"
            :description="__('ui.no_requirements_help')" />
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($requirements as $requirement)
                <section class="detail-card">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span class="detail-card-icon"><flux:icon.clipboard-document-check class="size-5" /></span>
                            <h2 class="detail-card-title">{{ $requirement->name }}</h2>
                            <p class="mt-1 text-sm text-[#6f797f]">{{ $requirement->course->title }}</p>
                        </div>
                        <span class="status-pill {{ $requirement->status->pillModifier() }}">{{ $requirement->status->label() }}</span>
                    </div>

                    <dl class="mt-5 grid gap-4 sm:grid-cols-3">
                        <div>
                            <dt class="metric-label">{{ __('Frequency') }}</dt>
                            <dd class="mt-1 text-sm font-bold text-[#262d33]">{{ $requirement->frequencyLabel() }}</dd>
                        </div>
                        <div>
                            <dt class="metric-label">{{ __('Renewal') }}</dt>
                            <dd class="mt-1 text-sm font-bold text-[#262d33]">{{ $requirement->renewal_basis->label() }}</dd>
                        </div>
                        <div>
                            <dt class="metric-label">{{ __('Deadline') }}</dt>
                            <dd class="mt-1 text-sm font-bold text-[#262d33]">{{ trans_choice('ui.days_after_assignment', $requirement->due_days_after_assignment, ['count' => $requirement->due_days_after_assignment]) }}</dd>
                        </div>
                    </dl>

                    <div class="mt-5 border-t border-[#eef1f4] pt-4">
                        <p class="metric-label">{{ __('Audience') }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @forelse ($requirement->targets as $target)
                                <span class="status-pill status-pill--accent">{{ $target->describe() }}</span>
                            @empty
                                <span class="text-sm text-[#8a9298]">{{ __('ui.no_targets') }}</span>
                            @endforelse
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-between border-t border-[#eef1f4] pt-4 text-xs">
                        <span class="text-[#8a9298]">{{ trans_choice('ui.people_in_scope', $inScope[$requirement->id], ['count' => $inScope[$requirement->id]]) }}</span>
                        <span class="font-bold text-[#1c6b84]">{{ trans_choice('ui.assignments_count', $requirement->assignments_count, ['count' => $requirement->assignments_count]) }}</span>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
