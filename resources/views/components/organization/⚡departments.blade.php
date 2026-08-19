<?php

use App\Enums\Permission;
use App\Models\Department;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public string $code = '';

    public function save(AuditLogger $audit): void
    {
        // Backend authorization is mandatory — hiding the form is presentation only.
        Gate::authorize(Permission::DepartmentsManage->value);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', Rule::unique('departments', 'code')],
        ]);

        $department = Department::query()->create($validated + ['status' => 'active']);

        $audit->log('department.created', $department, after: $validated);

        $this->reset(['name', 'code']);

        session()->flash('status', __('ui.department_created'));
    }

    public function toggleStatus(int $departmentId, AuditLogger $audit): void
    {
        Gate::authorize(Permission::DepartmentsManage->value);

        $department = Department::query()->findOrFail($departmentId);
        $before = $department->status;
        $department->update(['status' => $before === 'active' ? 'inactive' : 'active']);

        $audit->log('department.status_changed', $department, ['status' => $before], ['status' => $department->status]);
    }

    public function with(): array
    {
        return [
            'departments' => Department::query()
                ->withCount(['users', 'jobFunctions'])
                ->orderBy('name')
                ->get(),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.organization')"
        :title="__('Departments')"
        :description="__('ui.departments_page_description')" />

    @if (session('status'))
        <flux:callout variant="success" :heading="session('status')" />
    @endif

    @can(App\Enums\Permission::DepartmentsManage->value)
        <div class="form-panel rounded-[20px] border border-[#dde3e7] p-5 sm:p-6">
            <h2 class="text-base font-bold text-[#262d33]">{{ __('New department') }}</h2>
            <form wire:submit="save" class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_200px_auto] sm:items-end">
                <flux:input wire:model="name" class="admin-control" :label="__('Name')" :placeholder="__('Operations')" />
                <flux:input wire:model="code" class="admin-control" :label="__('Code')" :placeholder="__('OPS')" />
                <flux:button type="submit" variant="primary" class="admin-primary-action">
                    <span wire:loading.remove wire:target="save">{{ __('Add department') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </flux:button>
            </form>
        </div>
    @endcan

    @if ($departments->isEmpty())
        <x-empty-state
            icon="building-office-2"
            :title="__('ui.no_departments')"
            :description="__('ui.no_departments_help')" />
    @else
        <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7] shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>{{ __('Department') }}</th>
                        <th>{{ __('Code') }}</th>
                        <th class="text-right">{{ __('People') }}</th>
                        <th class="text-right">{{ __('Job functions') }}</th>
                        <th>{{ __('Status') }}</th>
                        @can(App\Enums\Permission::DepartmentsManage->value)
                            <th class="text-right">{{ __('Actions') }}</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach ($departments as $department)
                        <tr class="border-t">
                            <td class="font-semibold text-[#262d33]">{{ $department->name }}</td>
                            <td class="text-[#5f6a71]"><code>{{ $department->code }}</code></td>
                            <td class="text-right text-[#5f6a71]">{{ $department->users_count }}</td>
                            <td class="text-right text-[#5f6a71]">{{ $department->job_functions_count }}</td>
                            <td>
                                <span class="status-pill {{ $department->status === 'active' ? 'status-pill--positive' : 'status-pill--neutral' }}">
                                    {{ $department->status === 'active' ? __('Active') : __('Inactive') }}
                                </span>
                            </td>
                            @can(App\Enums\Permission::DepartmentsManage->value)
                                <td class="text-right">
                                    <flux:button wire:click="toggleStatus({{ $department->id }})" variant="ghost" size="sm">
                                        {{ $department->status === 'active' ? __('Deactivate') : __('Activate') }}
                                    </flux:button>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
