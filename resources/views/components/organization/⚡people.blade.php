<?php

use App\Enums\UserStatus;
use App\Actions\People\QueueWorkosInvitations;
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

    /** @var list<int|string> */
    public array $selected = [];

    public function updated(): void
    {
        $this->resetPage();
    }

    public function inviteSelected(QueueWorkosInvitations $action): void
    {
        $this->queueInvitations($action, false);
    }

    public function inviteAllPending(QueueWorkosInvitations $action): void
    {
        $this->queueInvitations($action, true);
    }

    private function queueInvitations(QueueWorkosInvitations $action, bool $allPending): void
    {
        try {
            $count = $action->handle($this->selected, $allPending);
            $this->selected = [];
            session()->flash('status', trans_choice(':count invitation queued|:count invitations queued', $count, ['count' => $count]));
        } catch (\RuntimeException $exception) {
            $this->addError('invitations', $exception->getMessage());
        }
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
            'pendingInvitationsCount' => User::query()
                ->whereIn('status', [UserStatus::Active, UserStatus::Invited])
                ->whereNull('workos_invitation_id')
                ->count(),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.organization')"
        :title="__('People')"
        :description="__('ui.people_page_description')">
        <div class="flex items-center gap-3">
            <span class="status-pill status-pill--accent">{{ trans_choice('ui.results_count', $people->total(), ['count' => $people->total()]) }}</span>
            @can(App\Enums\Permission::PeopleImport->value)
                <flux:button href="{{ route('people.import') }}" wire:navigate variant="primary" class="admin-primary-action">{{ __('Import people') }}</flux:button>
            @endcan
            @can(App\Enums\Permission::PeopleInvite->value)
                <flux:button wire:click="inviteAllPending" wire:confirm="{{ __('Queue invitations for every person who has not been invited yet?') }}" wire:loading.attr="disabled" variant="ghost">
                    {{ __('Invite all pending (:count)', ['count' => $pendingInvitationsCount]) }}
                </flux:button>
                @if (count($selected) > 0)
                    <flux:button wire:click="inviteSelected" wire:confirm="{{ __('Queue invitations for the selected people?') }}" wire:loading.attr="disabled" variant="primary">
                        {{ __('Invite selected (:count)', ['count' => count($selected)]) }}
                    </flux:button>
                @endif
            @endcan
        </div>
    </x-page-hero>

    @if (session('status')) <flux:callout variant="success" :heading="session('status')" /> @endif
    @error('invitations') <flux:callout variant="danger" :heading="$message" /> @enderror

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
                        @can(App\Enums\Permission::PeopleInvite->value)<th class="w-12"><span class="sr-only">{{ __('Select') }}</span></th>@endcan
                        <th>{{ __('Employee') }}</th>
                        <th>{{ __('Department') }}</th>
                        <th>{{ __('Job function') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('WorkOS invitation') }}</th>
                        <th class="text-right">{{ __('Open') }}</th>
                        <th class="text-right">{{ __('Overdue') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($people as $person)
                        <tr class="border-t">
                            @can(App\Enums\Permission::PeopleInvite->value)
                                <td><flux:checkbox wire:model.live="selected" value="{{ $person->id }}" :disabled="! in_array($person->status, [App\Enums\UserStatus::Active, App\Enums\UserStatus::Invited], true)" :aria-label="__('Select :name', ['name' => $person->name])" /></td>
                            @endcan
                            <td>
                                <a href="{{ route('people.show', ['user' => $person]) }}" wire:navigate class="font-semibold text-[#262d33] hover:text-[#1c6b84]">{{ $person->name }}</a>
                                <span class="block text-xs text-[#8a9298]">{{ $person->email }}</span>
                            </td>
                            <td class="text-[#5f6a71]">{{ $person->departments->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="text-[#5f6a71]">{{ $person->jobFunctions->pluck('name')->join(', ') ?: '—' }}</td>
                            <td><span class="status-pill {{ $person->status->pillModifier() }}">{{ $person->status->label() }}</span></td>
                            <td>
                                @if ($person->invitation_sent_at)
                                    <span class="status-pill status-pill--accent">{{ __('Sent') }}</span>
                                @elseif ($person->workos_user_id)
                                    <span class="status-pill status-pill--positive">{{ __('Linked') }}</span>
                                @else
                                    <span class="status-pill status-pill--neutral">{{ __('Not invited') }}</span>
                                @endif
                            </td>
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
