<?php

use App\Enums\Permission;
use App\Models\JobFunction;
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
        Gate::authorize(Permission::JobFunctionsManage->value);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', Rule::unique('job_functions', 'code')],
        ]);

        $jobFunction = JobFunction::query()->create($validated + ['status' => 'active']);

        $audit->log('job_function.created', $jobFunction, after: $validated);

        $this->reset(['name', 'code']);

        session()->flash('status', __('ui.job_function_created'));
    }

    public function toggleStatus(int $jobFunctionId, AuditLogger $audit): void
    {
        Gate::authorize(Permission::JobFunctionsManage->value);

        $jobFunction = JobFunction::query()->findOrFail($jobFunctionId);
        $before = $jobFunction->status;
        $jobFunction->update(['status' => $before === 'active' ? 'inactive' : 'active']);

        $audit->log('job_function.status_changed', $jobFunction, ['status' => $before], ['status' => $jobFunction->status]);
    }

    public function with(): array
    {
        return [
            'jobFunctions' => JobFunction::query()
                ->withCount(['users', 'departments'])
                ->orderBy('name')
                ->get(),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.organization')"
        :title="__('Job functions')"
        :description="__('ui.job_functions_page_description')" />

    @if (session('status'))
        <flux:callout variant="success" :heading="session('status')" />
    @endif

    @can(App\Enums\Permission::JobFunctionsManage->value)
        <div class="form-panel rounded-[20px] border border-[#dde3e7] p-5 sm:p-6">
            <h2 class="text-base font-bold text-[#262d33]">{{ __('New job function') }}</h2>
            <form wire:submit="save" class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_200px_auto] sm:items-end">
                <flux:input wire:model="name" class="admin-control" :label="__('Name')" :placeholder="__('Supervisor')" />
                <flux:input wire:model="code" class="admin-control" :label="__('Code')" :placeholder="__('SUP')" />
                <flux:button type="submit" variant="primary" class="admin-primary-action">
                    <span wire:loading.remove wire:target="save">{{ __('Add job function') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </flux:button>
            </form>
        </div>
    @endcan

    @if ($jobFunctions->isEmpty())
        <x-empty-state
            icon="identification"
            :title="__('ui.no_job_functions')"
            :description="__('ui.no_job_functions_help')" />
    @else
        <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7] shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>{{ __('Job function') }}</th>
                        <th>{{ __('Code') }}</th>
                        <th class="text-right">{{ __('People') }}</th>
                        <th class="text-right">{{ __('Departments') }}</th>
                        <th>{{ __('Status') }}</th>
                        @can(App\Enums\Permission::JobFunctionsManage->value)
                            <th class="text-right">{{ __('Actions') }}</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach ($jobFunctions as $jobFunction)
                        <tr class="border-t">
                            <td class="font-semibold text-[#262d33]">{{ $jobFunction->name }}</td>
                            <td class="text-[#5f6a71]"><code>{{ $jobFunction->code }}</code></td>
                            <td class="text-right text-[#5f6a71]">{{ $jobFunction->users_count }}</td>
                            <td class="text-right text-[#5f6a71]">{{ $jobFunction->departments_count }}</td>
                            <td>
                                <span class="status-pill {{ $jobFunction->status === 'active' ? 'status-pill--positive' : 'status-pill--neutral' }}">
                                    {{ $jobFunction->status === 'active' ? __('Active') : __('Inactive') }}
                                </span>
                            </td>
                            @can(App\Enums\Permission::JobFunctionsManage->value)
                                <td class="text-right">
                                    <flux:button wire:click="toggleStatus({{ $jobFunction->id }})" variant="ghost" size="sm">
                                        {{ $jobFunction->status === 'active' ? __('Deactivate') : __('Activate') }}
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
