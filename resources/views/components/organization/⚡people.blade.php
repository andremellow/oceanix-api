<?php

use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $department_id = '';

    public string $job_function_id = '';

    public string $status = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $query = User::query()
            ->with(['departments', 'jobFunctions'])
            ->withCount([
                'assignments as open_assignments_count' => fn ($scoped) => $scoped->open(),
                'assignments as overdue_assignments_count' => fn ($scoped) => $scoped->overdue(),
            ]);

        if ($this->search !== '') {
            $term = '%'.strtolower($this->search).'%';
            $query->where(fn ($scoped) => $scoped
                ->whereRaw('lower(name) like ?', [$term])
                ->orWhereRaw('lower(email) like ?', [$term])
                ->orWhereRaw('lower(coalesce(employee_id, \'\')) like ?', [$term]));
        }

        if ($this->department_id !== '') {
            $query->inDepartment((int) $this->department_id);
        }

        if ($this->job_function_id !== '') {
            $query->inJobFunction((int) $this->job_function_id);
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return [
            'people' => $query->orderBy('name')->paginate(25),
            'departments' => Department::query()->active()->orderBy('name')->get(),
            'jobFunctions' => JobFunction::query()->active()->orderBy('name')->get(),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.organization')"
        :title="__('People')"
        :description="__('ui.people_page_description')">
        <span class="status-pill status-pill--accent">{{ trans_choice('ui.results_count', $people->total(), ['count' => $people->total()]) }}</span>
    </x-page-hero>

    <div class="form-panel rounded-[20px] border border-[#dde3e7] p-4 sm:p-5">
        <div class="grid gap-3 lg:grid-cols-4">
            <flux:input wire:model.live.debounce.400ms="search" class="admin-control" icon="magnifying-glass" :label="__('Search')" :placeholder="__('Name, email or employee ID')" />
            <flux:select wire:model.live="department_id" class="admin-control" :label="__('Department')">
                <option value="">{{ __('All departments') }}</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="job_function_id" class="admin-control" :label="__('Job function')">
                <option value="">{{ __('All job functions') }}</option>
                @foreach ($jobFunctions as $jobFunction)
                    <option value="{{ $jobFunction->id }}">{{ $jobFunction->name }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="status" class="admin-control" :label="__('Status')">
                <option value="">{{ __('All statuses') }}</option>
                @foreach (UserStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($people->isEmpty())
        <x-empty-state
            icon="user-group"
            :title="__('ui.no_people')"
            :description="__('ui.no_people_help')" />
    @else
        <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7] shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>{{ __('Employee') }}</th>
                        <th>{{ __('Department') }}</th>
                        <th>{{ __('Job function') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="text-right">{{ __('Open') }}</th>
                        <th class="text-right">{{ __('Overdue') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($people as $person)
                        <tr class="border-t">
                            <td>
                                <a href="{{ route('people.show', $person) }}" wire:navigate class="font-semibold text-[#262d33] hover:text-[#1c6b84]">{{ $person->name }}</a>
                                <span class="block text-xs text-[#8a9298]">{{ $person->email }}</span>
                            </td>
                            <td class="text-[#5f6a71]">{{ $person->departments->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="text-[#5f6a71]">{{ $person->jobFunctions->pluck('name')->join(', ') ?: '—' }}</td>
                            <td><span class="status-pill {{ $person->status->pillModifier() }}">{{ $person->status->label() }}</span></td>
                            <td class="text-right font-bold text-[#5f6a71]">{{ $person->open_assignments_count }}</td>
                            <td class="text-right font-bold {{ $person->overdue_assignments_count > 0 ? 'text-[#b23a3a]' : 'text-[#8a9298]' }}">{{ $person->overdue_assignments_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>{{ $people->links() }}</div>
    @endif
</div>
