<?php

use App\Actions\People\AssignAccessProfile;
use App\Actions\People\AssignManagementScopes;
use App\Models\Department;
use App\Models\JobFunction;
use App\Actions\People\SendWorkosInvitation;
use App\Models\Role;
use App\Models\User;
use App\Services\Requirements\RequirementSchedulePreview;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public User $user;

    public array $managedDepartmentIds = [];

    public array $managedJobFunctionIds = [];

    public ?int $confirmingAdministratorRoleId = null;

    public string $administratorConfirmation = '';

    public function mount(User $user): void
    {
        $this->authorize('view', $user);

        $this->user = $user->load(['departments', 'jobFunctions', 'roles', 'managedDepartments', 'managedJobFunctions']);
        $this->managedDepartmentIds = $this->user->managedDepartments->modelKeys();
        $this->managedJobFunctionIds = $this->user->managedJobFunctions->modelKeys();
    }

    public function with(RequirementSchedulePreview $schedulePreview): array
    {
        return [
            'roles' => Role::query()->active()->orderBy('name')->get(),
            'departments' => Department::query()->active()->orderBy('name')->get(),
            'jobFunctions' => JobFunction::query()->active()->orderBy('name')->get(),
            'assignments' => $this->user->assignments()
                ->with(['course', 'courseVersion', 'certificate'])
                ->orderByRaw('due_at is null')
                ->orderBy('due_at')
                ->get(),
            'upcomingSchedule' => $schedulePreview->paginateForUser(
                $this->user,
                $this->getPage('personSchedulePage'),
            ),
        ];
    }

    public function saveManagementScopes(AssignManagementScopes $action): void
    {
        $this->authorize('manage', $this->user);
        $this->user = $action->handle($this->user, $this->managedDepartmentIds, $this->managedJobFunctionIds);
        session()->flash('status', __('Management responsibilities updated.'));
    }

    public function toggleRole(int $roleId, AssignAccessProfile $action): void
    {
        $this->authorize('assignRoles', $this->user);
        $role = Role::query()->findOrFail($roleId);

        if ($role->key === 'admin') {
            abort_unless(auth()->user()->isAdmin(), 403);
            $this->confirmingAdministratorRoleId = $role->id;
            $this->administratorConfirmation = '';

            return;
        }

        $action->toggle($this->user, $role);
        $this->user->load('roles');
    }

    public function confirmAdministratorRole(AssignAccessProfile $action): void
    {
        $this->authorize('assignRoles', $this->user);
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->resetErrorBag('administratorConfirmation');

        $role = Role::query()->whereKey($this->confirmingAdministratorRoleId)->where('key', 'admin')->firstOrFail();

        if ($this->administratorConfirmation !== $this->user->email) {
            $this->addError('administratorConfirmation', __('Type the exact email address to confirm this administrator access change.'));

            return;
        }

        $action->toggle($this->user, $role);
        $this->user->load('roles');
        $this->reset('confirmingAdministratorRoleId', 'administratorConfirmation');
        session()->flash('status', __('Administrator access updated.'));
    }

    public function sendInvitation(SendWorkosInvitation $action): void
    {
        $this->authorize('invite', $this->user);

        try {
            $this->user = $action->handle($this->user);
            session()->flash('status', __('Invitation sent through WorkOS.'));
        } catch (\RuntimeException $exception) {
            $this->addError('invitation', $exception->getMessage());
        }
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="$user->employee_id ?: __('ui.no_employee_id')"
        :title="$user->name"
        :description="$user->email">
        <span class="status-pill {{ $user->status->pillModifier() }}">{{ $user->status->label() }}</span>
        @if (app()->environment(['local', 'testing']) && auth()->user()->isAdmin() && auth()->id() !== $user->id)
            <form method="POST" action="{{ route('impersonation.start', ['company' => app(App\Tenancy\TenantContext::class)->get(), 'user' => $user]) }}">
                @csrf
                <flux:button type="submit" variant="ghost" size="sm" icon="user-circle">{{ __('Impersonate user') }}</flux:button>
            </form>
        @endif
        @can('invite', $user)
            <flux:button wire:click="sendInvitation" wire:loading.attr="disabled" variant="primary" size="sm">
                {{ $user->invitation_sent_at ? __('Resend invitation') : __('Send invitation') }}
            </flux:button>
        @endcan
        <flux:button :href="route('people.index', ['company' => app(App\Tenancy\TenantContext::class)->get()])" wire:navigate variant="ghost" size="sm">{{ __('ui.back_to_people') }}</flux:button>
    </x-page-hero>

    <x-status-message />
    @error('invitation') <flux:callout variant="danger" :heading="$message" /> @enderror

    @if ($user->invitation_sent_at)
        <p class="text-sm text-[#6f797f]">{{ __('Last invitation sent :date', ['date' => $user->invitation_sent_at->locale(app()->getLocale())->translatedFormat('M j, Y H:i')]) }}</p>
    @endif

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
        <span class="detail-card-icon"><flux:icon.key class="size-5" /></span>
        <h2 class="detail-card-title">{{ __('Access profiles') }}</h2>
        <p class="mt-1 text-sm text-[#6f797f]">{{ __('Access belongs to this person in this company only.') }}</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ($roles as $role)
                @php($assigned = $user->roles->contains($role))
                @can('assignRoles', $user)
                    <button type="button" wire:click="toggleRole({{ $role->id }})" class="status-pill {{ $role->key === 'admin' ? 'border-red-200 bg-red-50 text-red-700' : ($assigned ? 'status-pill--accent' : 'status-pill--neutral') }}" aria-pressed="{{ $assigned ? 'true' : 'false' }}">
                        @if ($role->key === 'admin')<flux:icon.shield-exclamation class="mr-1 size-3.5" />@endif
                        {{ $role->name }}
                    </button>
                @else
                    <span class="status-pill {{ $assigned ? 'status-pill--accent' : 'status-pill--neutral' }}">{{ $role->name }}</span>
                @endcan
            @endforeach
        </div>
    </section>

    <flux:modal wire:model.self="confirmingAdministratorRoleId" class="max-w-lg">
        @php($administratorAssigned = $user->roles->contains(fn ($role) => $role->key === 'admin'))
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $administratorAssigned ? __('Remove administrator access?') : __('Grant administrator access?') }}</flux:heading>
                <flux:text class="mt-2">{{ $administratorAssigned
                    ? __('This person will lose unrestricted access to this company.')
                    : __('Administrator gives unrestricted access to company data, settings, people and access profiles.') }}</flux:text>
            </div>
            <flux:callout variant="danger" icon="shield-exclamation" :heading="__('High-impact access change')">
                {{ __('To continue, type :email exactly.', ['email' => $user->email]) }}
            </flux:callout>
            <flux:input wire:model="administratorConfirmation" :label="__('Confirmation email')" :placeholder="$user->email" autocomplete="off" />
            @error('administratorConfirmation') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-3">
                <flux:button x-on:click="$wire.confirmingAdministratorRoleId = null; $wire.administratorConfirmation = ''" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="confirmAdministratorRole" variant="danger" wire:loading.attr="disabled">{{ $administratorAssigned ? __('Remove administrator') : __('Grant administrator') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <section class="detail-card">
        <span class="detail-card-icon"><flux:icon.calendar-days class="size-5" /></span>
        <h2 class="detail-card-title">{{ __('Upcoming training schedule') }}</h2>
        <p class="mt-1 text-sm text-[#6f797f]">{{ __('Expected and already-created occurrences for the next 3 months.') }}</p>
        <div class="mt-5">
            @if ($upcomingSchedule->isEmpty())
                <x-empty-state icon="calendar-days" :title="__('No upcoming occurrences')" :description="__('This person has no scheduled training in the next 3 months.')" />
            @else
                <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7]">
                    <table class="w-full text-left text-sm">
                        <thead><tr><th>{{ __('Requirement') }}</th><th>{{ __('Course') }}</th><th>{{ __('Cycle') }}</th><th>{{ __('Available') }}</th><th>{{ __('Due date') }}</th><th>{{ __('Status') }}</th></tr></thead>
                        <tbody>
                            @foreach ($upcomingSchedule as $row)
                                <tr class="border-t" wire:key="person-schedule-{{ $row['requirement_id'] }}-{{ $row['cycle'] }}">
                                    <td class="font-semibold text-[#262d33]">{{ $row['requirement_name'] }}</td>
                                    <td>{{ $row['course_title'] }}</td>
                                    <td>{{ $row['cycle'] }}</td>
                                    <td>{{ $row['available_at']?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</td>
                                    <td>{{ $row['due_at']->locale(app()->getLocale())->translatedFormat('M j, Y') }}</td>
                                    <td><span class="status-pill {{ $row['materialized'] ? 'status-pill--accent' : 'status-pill--neutral' }}">{{ $row['status'] }}{{ $row['estimated'] ? ' · '.__('Estimated') : '' }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $upcomingSchedule->links() }}</div>
            @endif
        </div>
    </section>

    <section class="detail-card space-y-5">
        <div>
            <span class="detail-card-icon"><flux:icon.user-group class="size-5" /></span>
            <h2 class="detail-card-title">{{ __('Management responsibilities') }}</h2>
            <p class="mt-1 text-sm text-[#6f797f]">{{ __('Select the departments and job functions this person manages.') }}</p>
        </div>
        <form wire:submit="saveManagementScopes" class="grid gap-5 lg:grid-cols-2">
            <fieldset class="space-y-2"><legend class="mb-2 text-sm font-bold text-[#262d33]">{{ __('Departments') }}</legend>
                @forelse ($departments as $department)<flux:checkbox wire:model="managedDepartmentIds" value="{{ $department->id }}" :label="$department->name" />@empty<p class="text-sm text-[#8a9298]">{{ __('No active departments') }}</p>@endforelse
            </fieldset>
            <fieldset class="space-y-2"><legend class="mb-2 text-sm font-bold text-[#262d33]">{{ __('Job functions') }}</legend>
                @forelse ($jobFunctions as $jobFunction)<flux:checkbox wire:model="managedJobFunctionIds" value="{{ $jobFunction->id }}" :label="$jobFunction->name" />@empty<p class="text-sm text-[#8a9298]">{{ __('No active job functions') }}</p>@endforelse
            </fieldset>
            @can('manage', $user)<div class="lg:col-span-2"><flux:button type="submit" variant="primary">{{ __('Save management responsibilities') }}</flux:button></div>@endcan
        </form>
        @error('management') <flux:callout variant="danger" :heading="$message" /> @enderror
    </section>

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
