<?php

use App\Models\User;
use Livewire\Component;

new class extends Component
{
    public User $user;

    public function mount(User $user): void
    {
        $this->authorize('view', $user);

        $this->user = $user->load(['departments', 'jobFunctions', 'roles']);
    }

    public function with(): array
    {
        return [
            'assignments' => $this->user->assignments()
                ->with(['course', 'courseVersion', 'certificate'])
                ->orderByRaw('due_at is null')
                ->orderBy('due_at')
                ->get(),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="$user->employee_id ?: __('ui.no_employee_id')"
        :title="$user->name"
        :description="$user->email">
        <span class="status-pill {{ $user->status->pillModifier() }}">{{ $user->status->label() }}</span>
        <flux:button :href="route('people.index')" wire:navigate variant="ghost" size="sm">{{ __('ui.back_to_people') }}</flux:button>
    </x-page-hero>

    <div class="grid gap-5 lg:grid-cols-3">
        <section class="detail-card">
            <span class="detail-card-icon"><flux:icon.building-office-2 class="size-5" /></span>
            <h2 class="detail-card-title">{{ __('Departments') }}</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @forelse ($user->departments as $department)
                    <span class="status-pill status-pill--accent">{{ $department->name }}</span>
                @empty
                    <span class="text-sm text-[#8a9298]">{{ __('Not provided') }}</span>
                @endforelse
            </div>
        </section>
        <section class="detail-card">
            <span class="detail-card-icon"><flux:icon.identification class="size-5" /></span>
            <h2 class="detail-card-title">{{ __('Job functions') }}</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @forelse ($user->jobFunctions as $jobFunction)
                    <span class="status-pill status-pill--accent">{{ $jobFunction->name }}</span>
                @empty
                    <span class="text-sm text-[#8a9298]">{{ __('Not provided') }}</span>
                @endforelse
            </div>
        </section>
        <section class="detail-card">
            <span class="detail-card-icon"><flux:icon.calendar class="size-5" /></span>
            <h2 class="detail-card-title">{{ __('Employment') }}</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-[#8a9298]">{{ __('Hired') }}</dt>
                    <dd class="font-semibold text-[#262d33]">{{ $user->hired_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-[#8a9298]">{{ __('Terminated') }}</dt>
                    <dd class="font-semibold text-[#262d33]">{{ $user->terminated_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-[#8a9298]">{{ __('Access profiles') }}</dt>
                    <dd class="font-semibold text-[#262d33]">{{ $user->roles->pluck('name')->join(', ') ?: '—' }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <section class="detail-card">
        <span class="detail-card-icon"><flux:icon.rectangle-stack class="size-5" /></span>
        <h2 class="detail-card-title">{{ __('Training record') }}</h2>
        <p class="mt-1 text-sm text-[#6f797f]">{{ __('ui.training_record_help') }}</p>

        <div class="mt-5">
            @if ($assignments->isEmpty())
                <x-empty-state
                    icon="rectangle-stack"
                    :title="__('ui.no_assignments')"
                    :description="__('ui.no_assignments_person_help')" />
            @else
                <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7]">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr>
                                <th>{{ __('Course') }}</th>
                                <th>{{ __('Origin') }}</th>
                                <th>{{ __('Due date') }}</th>
                                <th>{{ __('Completed') }}</th>
                                <th>{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($assignments as $assignment)
                                <tr class="border-t">
                                    <td>
                                        <span class="font-semibold text-[#262d33]">{{ $assignment->course->title }}</span>
                                        <span class="block text-xs text-[#8a9298]">{{ __('Version :number', ['number' => $assignment->courseVersion->version_number]) }}</span>
                                    </td>
                                    <td class="text-[#5f6a71]">{{ $assignment->origin_type->label() }}</td>
                                    <td class="text-[#5f6a71]">{{ $assignment->due_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</td>
                                    <td class="text-[#5f6a71]">{{ $assignment->completed_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</td>
                                    <td><span class="status-pill {{ $assignment->status->pillModifier() }}">{{ $assignment->status->label() }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>
</div>
