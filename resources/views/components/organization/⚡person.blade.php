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

new class extends \Oceanix\Platform\Livewire\Person
{
    public array $managedDepartmentIds = [];
    public array $managedJobFunctionIds = [];
    public function personKicker(): string
    {
        return $this->user->employee_id ?: __('ui.no_employee_id');
    }

    public function mount($user): void
    {
        parent::mount($user);
        $user = $this->user;

        $this->user = $user->load(['departments', 'jobFunctions', 'roles', 'managedDepartments', 'managedJobFunctions']);
        $this->managedDepartmentIds = $this->user->managedDepartments->modelKeys();
        $this->managedJobFunctionIds = $this->user->managedJobFunctions->modelKeys();
    }

    public function with(): array
    {
        $this->user->refresh();
        return [
            ...parent::with(),
            'departments' => Department::query()->active()->orderBy('name')->get(),
            'jobFunctions' => JobFunction::query()->active()->orderBy('name')->get(),
            'assignments' => $this->user->assignments()
                ->with(['course', 'courseVersion', 'certificate'])
                ->orderByRaw('due_at is null')
                ->orderBy('due_at')
                ->get(),
            'upcomingSchedule' => app(RequirementSchedulePreview::class)->paginateForUser(
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

};
?>
@include('oceanix-platform::person')
