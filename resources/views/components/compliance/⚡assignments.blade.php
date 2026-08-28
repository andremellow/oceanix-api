<?php

use App\Actions\Assignments\CreateManualAssignment;
use App\Enums\AssignmentStatus;
use App\Models\Course;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceOverview;
use App\Services\Organization\ManagedPeopleScope;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $department_id = '';

    public string $job_function_id = '';

    public string $course_id = '';

    public string $status = 'open';

    public string $due_bucket = '';

    public bool $assigning = false;

    /** @var array<string, string> */
    public array $assignment = ['user_id' => '', 'course_id' => '', 'due_at' => '', 'available_at' => ''];

    public function updated(): void
    {
        $this->resetPage();
    }

    public function startAssigning(): void
    {
        $this->authorize('create', UserTrainingAssignment::class);

        $this->assignment = [
            'user_id' => '',
            'course_id' => '',
            'due_at' => now()->addDays(30)->toDateString(),
            'available_at' => '',
        ];
        $this->assigning = true;
    }

    /** Assigns a course to one person without a requirement — docs/product-spec.md §9. */
    public function assign(CreateManualAssignment $action, ManagedPeopleScope $managedPeople): void
    {
        $this->authorize('create', UserTrainingAssignment::class);

        $validated = $this->validate([
            'assignment.user_id' => ['required', 'exists:users,id'],
            'assignment.course_id' => ['required', 'exists:courses,id'],
            'assignment.due_at' => ['nullable', 'date'],
            'assignment.available_at' => ['nullable', 'date'],
        ])['assignment'];

        $course = Course::query()->findOrFail($validated['course_id']);
        $person = User::query()->findOrFail($validated['user_id']);

        abort_unless($managedPeople->canView(auth()->user(), $person), 403);

        if ($course->current_published_version_id === null) {
            $this->addError('assignment.course_id', __('ui.course_not_publishable'));

            return;
        }

        $action->handle(
            $person,
            $course,
            dueAt: $validated['due_at'] ? Carbon::parse($validated['due_at'])->endOfDay() : null,
            availableAt: $validated['available_at'] ? Carbon::parse($validated['available_at'])->startOfDay() : null,
        );

        $this->assigning = false;
        $this->resetPage();

        session()->flash('status', __('ui.assignment_created'));
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'department_id', 'job_function_id', 'course_id', 'due_bucket']);
        $this->status = 'open';
        $this->resetPage();
    }

    public function with(ComplianceOverview $overview, ManagedPeopleScope $managedPeople): array
    {
        $facets = $overview->assignmentFacets(auth()->user());

        return [
            'assignments' => $overview->assignments([
                'search' => $this->search,
                'department_id' => $this->department_id ?: null,
                'job_function_id' => $this->job_function_id ?: null,
                'course_id' => $this->course_id ?: null,
                'status' => $this->status ?: null,
                'due_bucket' => $this->due_bucket ?: null,
            ], auth()->user())->paginate(25),
            ...$facets,
            'assignableCourses' => Course::query()->assignable()->orderBy('title')->get(),
            'people' => auth()->user()->can('create', UserTrainingAssignment::class)
                ? User::query()
                    ->whereKey($managedPeople->userIds(auth()->user()))
                    ->eligibleForTraining()
                    ->orderBy('name')
                    ->get()
                : collect(),
            'buckets' => $overview->dueBuckets(),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.compliance')"
        :title="__('Assignments')"
        :description="__('ui.assignments_page_description')">
        <span class="status-pill status-pill--accent">{{ trans_choice('ui.results_count', $assignments->total(), ['count' => $assignments->total()]) }}</span>
        @can(App\Enums\Permission::ComplianceReportsExport->value)
            <flux:button
                :href="route('assignments.export', array_filter([
                    'search' => $search,
                    'department_id' => $department_id,
                    'job_function_id' => $job_function_id,
                    'course_id' => $course_id,
                    'status' => $status,
                    'due_bucket' => $due_bucket,
                ]))"
                variant="ghost"
                icon="arrow-down-tray">{{ __('Export CSV') }}</flux:button>
        @endcan
        @can('create', App\Models\UserTrainingAssignment::class)
            <flux:button wire:click="startAssigning" variant="primary" class="admin-primary-action">{{ __('Assign training') }}</flux:button>
        @endcan
    </x-page-hero>

    <x-status-message />

    <div class="form-panel rounded-[20px] border border-[#dde3e7] p-4 sm:p-5">
        <div class="grid gap-3 lg:grid-cols-3 xl:grid-cols-6">
            <flux:input wire:model.live.debounce.400ms="search" class="admin-control" icon="magnifying-glass" :label="__('Search')" :placeholder="__('Person or course')" />
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
            <flux:select wire:model.live="course_id" class="admin-control" :label="__('Course')">
                <option value="">{{ __('All courses') }}</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}">{{ $course->title }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="status" class="admin-control" :label="__('Status')">
                <option value="">{{ __('All statuses') }}</option>
                <option value="open">{{ __('Open') }}</option>
                @foreach (AssignmentStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="due_bucket" class="admin-control" :label="__('Deadline')">
                <option value="">{{ __('Any deadline') }}</option>
                @foreach ($buckets as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </flux:select>
        </div>
        <div class="mt-3 flex justify-end">
            <flux:button wire:click="clearFilters" variant="ghost" size="sm">{{ __('Clear filters') }}</flux:button>
        </div>
    </div>

    @if ($assignments->isEmpty())
        <x-empty-state
            icon="rectangle-stack"
            :title="__('ui.no_assignments')"
            :description="__('ui.no_assignments_help')" />
    @else
        <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7] shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>{{ __('Employee') }}</th>
                        <th>{{ __('Department') }}</th>
                        <th>{{ __('Job function') }}</th>
                        <th>{{ __('Course') }}</th>
                        <th>{{ __('Due date') }}</th>
                        <th>{{ __('Origin') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="text-right">{{ __('Days overdue') }}</th>
                        <th class="text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assignments as $assignment)
                        <tr class="border-t">
                            <td>
                                @can('view', $assignment->user)
                                    <a href="{{ route('people.show', ['company' => app(App\Tenancy\TenantContext::class)->get(), 'user' => $assignment->user]) }}" wire:navigate class="font-semibold text-[#262d33] hover:text-[#1c6b84]">{{ $assignment->user->name }}</a>
                                @else
                                    <span class="font-semibold text-[#262d33]">{{ $assignment->user->name }}</span>
                                @endcan
                                <span class="block text-xs text-[#8a9298]">{{ $assignment->user->email }}</span>
                            </td>
                            <td class="text-[#5f6a71]">{{ $assignment->user->departments->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="text-[#5f6a71]">{{ $assignment->user->jobFunctions->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="text-[#5f6a71]">
                                {{ $assignment->course->title }}
                                <span class="block text-xs text-[#8a9298]">{{ __('Version :number', ['number' => $assignment->courseVersion->version_number]) }}</span>
                            </td>
                            <td class="text-[#5f6a71]">{{ $assignment->due_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</td>
                            <td class="text-[#5f6a71]">{{ $assignment->origin_type->label() }}</td>
                            <td>
                                <span class="status-pill {{ $assignment->status->pillModifier() }}">{{ $assignment->status->label() }}</span>
                            </td>
                            <td class="text-right font-bold {{ $assignment->daysOverdue() > 0 ? 'text-[#b23a3a]' : 'text-[#8a9298]' }}">
                                {{ $assignment->daysOverdue() > 0 ? $assignment->daysOverdue() : '—' }}
                            </td>
                            <td class="text-right">
                                <flux:button :href="route('assignments.show', ['assignment' => $assignment])" wire:navigate variant="ghost" size="sm">
                                    {{ __('View details') }}
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>{{ $assignments->links() }}</div>
    @endif

    @can('create', App\Models\UserTrainingAssignment::class)
        <flux:modal wire:model.self="assigning" class="max-w-lg">
            <form wire:submit="assign" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Assign training') }}</flux:heading>
                <flux:text class="mt-2">{{ __('ui.manual_assignment_help') }}</flux:text>
            </div>

            <flux:select wire:model="assignment.user_id" class="admin-control" :label="__('Employee')">
                <option value="">{{ __('Select a person') }}</option>
                @foreach ($people as $person)
                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model="assignment.course_id" class="admin-control" :label="__('Course')">
                <option value="">{{ __('Select a course') }}</option>
                @foreach ($assignableCourses as $course)
                    <option value="{{ $course->id }}">{{ $course->title }}</option>
                @endforeach
            </flux:select>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input type="date" wire:model="assignment.available_at" class="admin-control" :label="__('Available from')" />
                <flux:input type="date" wire:model="assignment.due_at" class="admin-control" :label="__('Due date')" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:button x-on:click="$wire.assigning = false" variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="admin-primary-action">{{ __('Create assignment') }}</flux:button>
            </div>
            </form>
        </flux:modal>
    @endcan
</div>
