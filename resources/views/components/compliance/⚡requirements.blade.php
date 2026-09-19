<?php

use App\Actions\Requirements\AddRequirementTargets;
use App\Actions\Requirements\ChangeRequirementStatus;
use App\Actions\Requirements\SaveTrainingRequirement;
use App\Enums\FrequencyType;
use App\Enums\RenewalBasis;
use App\Enums\RequirementStatus;
use App\Enums\TargetScope;
use App\Exceptions\RequirementActivationException;
use App\Models\Course;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\TrainingRequirement;
use App\Models\TrainingRequirementTarget;
use App\Services\Audit\AuditLogger;
use App\Services\Requirements\RequirementEligibilityService;
use App\Services\Requirements\RequirementSchedulePreview;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public bool $editing = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $targeting = false;

    public ?int $targetingId = null;

    public string $targetSearch = '';

    public ?int $scheduleRequirementId = null;

    /** @var array<string, mixed> */
    public array $targetForm = [];

    public function mount(): void
    {
        $this->resetForm();
    }

    public function startCreating(): void
    {
        $this->authorize('create', TrainingRequirement::class);

        $this->resetForm();
        $this->editing = true;
    }

    public function startEditing(int $requirementId): void
    {
        $requirement = TrainingRequirement::query()->findOrFail($requirementId);

        $this->authorize('update', $requirement);

        $this->editingId = $requirement->id;
        $this->form = [
            'name' => $requirement->name,
            'course_id' => (string) $requirement->course_id,
            'frequency_type' => $requirement->frequency_type->value,
            'frequency_value' => (string) ($requirement->frequency_value ?? 12),
            'renewal_basis' => $requirement->renewal_basis->value,
            'assignment_lead_days' => (string) $requirement->assignment_lead_days,
            'due_days_after_assignment' => (string) $requirement->due_days_after_assignment,
            'effective_from' => $requirement->effective_from?->toDateString() ?? '',
            'effective_until' => $requirement->effective_until?->toDateString() ?? '',
        ];
        $this->editing = true;
    }

    public function save(SaveTrainingRequirement $action): void
    {
        $requirement = $this->editingId === null
            ? null
            : TrainingRequirement::query()->findOrFail($this->editingId);

        $requirement === null
            ? $this->authorize('create', TrainingRequirement::class)
            : $this->authorize('update', $requirement);

        $validated = $this->validate([
            'form.name' => ['required', 'string', 'max:200'],
            'form.course_id' => ['required', 'exists:courses,id'],
            'form.frequency_type' => ['required', 'in:once,days,months,years'],
            'form.frequency_value' => ['nullable', 'integer', 'min:1', 'max:120'],
            'form.renewal_basis' => ['required', 'in:from_completion,from_due_date'],
            'form.assignment_lead_days' => ['required', 'integer', 'min:0', 'max:365'],
            'form.due_days_after_assignment' => ['required', 'integer', 'min:1', 'max:365'],
            'form.effective_from' => ['nullable', 'date'],
            'form.effective_until' => ['nullable', 'date', 'after_or_equal:form.effective_from'],
        ], [
            'form.name.required' => __('Enter a name for the requirement.'),
            'form.name.max' => __('The requirement name may not exceed 200 characters.'),
            'form.course_id.required' => __('Select a course.'),
            'form.course_id.exists' => __('The selected course is no longer available.'),
            'form.frequency_value.integer' => __('Enter a whole number for the frequency.'),
            'form.frequency_value.min' => __('The frequency must be at least 1.'),
            'form.frequency_value.max' => __('The frequency may not exceed 120.'),
            'form.assignment_lead_days.required' => __('Enter how many days before the deadline the training should be assigned.'),
            'form.assignment_lead_days.integer' => __('Assignment lead time must be a whole number of days.'),
            'form.due_days_after_assignment.required' => __('Enter how many days the person has to complete the training.'),
            'form.due_days_after_assignment.integer' => __('The completion deadline must be a whole number of days.'),
            'form.effective_from.date' => __('Enter a valid start date.'),
            'form.effective_until.date' => __('Enter a valid end date.'),
            'form.effective_until.after_or_equal' => __('The end date must be on or after the start date.'),
        ])['form'];

        $action->handle($validated, $requirement);

        $this->editing = false;
        $this->resetForm();

        session()->flash('status', __('ui.requirement_saved'));
    }

    public function changeStatus(int $requirementId, string $status, ChangeRequirementStatus $action): void
    {
        $requirement = TrainingRequirement::query()->findOrFail($requirementId);

        $this->authorize('activate', $requirement);

        try {
            $action->handle($requirement, RequirementStatus::from($status));
        } catch (RequirementActivationException $e) {
            $this->addError('activation', $e->getMessage());

            return;
        }

        session()->flash('status', __('ui.requirement_status_changed'));
    }

    public function startTargeting(int $requirementId): void
    {
        $requirement = TrainingRequirement::query()->findOrFail($requirementId);

        $this->authorize('update', $requirement);

        $this->resetValidation();
        $this->targetSearch = '';
        $this->targetingId = $requirement->id;
        $this->targeting = true;
        $this->targetForm = ['scope_type' => TargetScope::Department->value, 'department_id' => '', 'job_function_ids' => []];
    }

    public function closeTargeting(): void
    {
        $this->targeting = false;
        $this->targetingId = null;
        $this->targetForm = [];
        $this->resetValidation();
        $this->targetSearch = '';
    }

    public function showSchedule(int $requirementId): void
    {
        $requirement = TrainingRequirement::query()->findOrFail($requirementId);
        $this->authorize('view', $requirement);
        $this->scheduleRequirementId = $requirement->id;
        $this->resetPage('requirementSchedulePage');
    }

    public function addTarget(AddRequirementTargets $action): void
    {
        $requirement = TrainingRequirement::query()->findOrFail($this->targetingId);

        $this->authorize('update', $requirement);

        $action->handle($requirement, $this->targetForm);

        $this->closeTargeting();
    }

    public function removeTarget(int $targetId, AuditLogger $audit): void
    {
        $target = TrainingRequirementTarget::query()->findOrFail($targetId);

        $this->authorize('update', $target->trainingRequirement);

        $audit->log('training_requirement.target_removed', $target->trainingRequirement, before: [
            'scope_type' => $target->scope_type->value,
            'target_id' => $target->id,
        ]);

        $target->delete();
    }

    public function with(RequirementEligibilityService $eligibility, RequirementSchedulePreview $schedulePreview): array
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
            'courses' => Course::query()->orderBy('title')->get(),
            'departments' => Department::query()->active()->orderBy('name')->get(),
            'jobFunctions' => JobFunction::query()->active()->orderBy('name')->get(),
            'scheduledRequirement' => $this->scheduleRequirementId === null
                ? null
                : TrainingRequirement::query()->with('course')->findOrFail($this->scheduleRequirementId),
            'scheduleRows' => $this->scheduleRequirementId === null
                ? null
                : $schedulePreview->paginateForRequirement(
                    TrainingRequirement::query()->findOrFail($this->scheduleRequirementId),
                    $this->getPage('requirementSchedulePage'),
                ),
        ];
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'name' => '',
            'course_id' => '',
            'frequency_type' => FrequencyType::Months->value,
            'frequency_value' => '12',
            'renewal_basis' => RenewalBasis::FromCompletion->value,
            'assignment_lead_days' => '30',
            'due_days_after_assignment' => '30',
            'effective_from' => '',
            'effective_until' => '',
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.compliance')"
        :title="__('Training requirements')"
        :description="__('ui.requirements_page_description')">
        <span class="status-pill status-pill--accent">{{ trans_choice('ui.results_count', $requirements->count(), ['count' => $requirements->count()]) }}</span>
        @can('create', App\Models\TrainingRequirement::class)
            <flux:button wire:click="startCreating" variant="primary" class="admin-primary-action">{{ __('New requirement') }}</flux:button>
        @endcan
    </x-page-hero>

    <x-status-message />

    @error('activation')
        <flux:callout variant="danger" :heading="$message" />
    @enderror

    @if ($requirements->isEmpty())
        <x-empty-state
            icon="clipboard-document-check"
            :title="__('ui.no_requirements')"
            :description="__('ui.no_requirements_help')" />
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($requirements as $requirement)
                <section class="detail-card" wire:key="requirement-{{ $requirement->id }}">
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
                        <div class="flex items-center justify-between gap-3">
                            <p class="metric-label">{{ __('Audience') }}</p>
                            @can('update', $requirement)
                                <flux:button wire:click="startTargeting({{ $requirement->id }})" variant="ghost" size="sm" icon="plus">{{ __('Add target') }}</flux:button>
                            @endcan
                        </div>

                        <div class="mt-2 flex flex-wrap gap-2">
                            @forelse ($requirement->targets as $target)
                                <span class="status-pill status-pill--accent">
                                    {{ $target->describe() }}
                                    @can('update', $requirement)
                                        <button type="button" wire:click="removeTarget({{ $target->id }})" class="ml-1 opacity-60 hover:opacity-100" aria-label="{{ __('Remove target') }}">&times;</button>
                                    @endcan
                                </span>
                            @empty
                                <span class="text-sm text-[#8a9298]">{{ __('ui.no_targets') }}</span>
                            @endforelse
                        </div>
                    </div>

                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-[#eef1f4] pt-4 text-xs">
                        <span class="text-[#8a9298]">{{ trans_choice('ui.people_in_scope', $inScope[$requirement->id], ['count' => $inScope[$requirement->id]]) }}</span>
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-[#1c6b84]">{{ trans_choice('ui.assignments_count', $requirement->assignments_count, ['count' => $requirement->assignments_count]) }}</span>
                            <flux:button wire:click="showSchedule({{ $requirement->id }})" variant="ghost" size="sm" icon="calendar-days">{{ __('View schedule') }}</flux:button>
                            @can('update', $requirement)
                                <flux:button wire:click="startEditing({{ $requirement->id }})" variant="ghost" size="sm">{{ __('Edit') }}</flux:button>
                            @endcan
                            @can('activate', $requirement)
                                @if ($requirement->status === App\Enums\RequirementStatus::Active)
                                    <flux:button wire:click="changeStatus({{ $requirement->id }}, 'paused')" variant="ghost" size="sm">{{ __('Pause') }}</flux:button>
                                @else
                                    <flux:button wire:click="changeStatus({{ $requirement->id }}, 'active')" variant="ghost" size="sm">{{ __('Activate') }}</flux:button>
                                @endif
                            @endcan
                        </div>
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    {{-- Requirement form --}}
    <flux:modal wire:model.self="editing" class="max-w-2xl">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit requirement') : __('New requirement') }}</flux:heading>
                <flux:text class="mt-2">{{ __('ui.requirement_form_help') }}</flux:text>
            </div>

            <flux:input wire:model="form.name" class="admin-control" :label="__('Name')" :placeholder="__('Offshore safety — Operations supervisors')" />

            <flux:select wire:model="form.course_id" class="admin-control" :label="__('Course')">
                <option value="">{{ __('Select a course') }}</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}">{{ $course->title }}</option>
                @endforeach
            </flux:select>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:select wire:model.live="form.frequency_type" class="admin-control" :label="__('Frequency')">
                    @foreach (App\Enums\FrequencyType::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </flux:select>
                @if ($form['frequency_type'] !== 'once')
                    <flux:input type="number" min="1" max="120" wire:model="form.frequency_value" class="admin-control" :label="__('Every')" />
                @endif
                <flux:select wire:model="form.renewal_basis" class="admin-control" :label="__('Renewal')">
                    @foreach (App\Enums\RenewalBasis::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input type="number" min="1" max="365" wire:model="form.due_days_after_assignment" class="admin-control" :label="__('Days to complete after assignment')" />
                <flux:input type="number" min="0" max="365" wire:model="form.assignment_lead_days" class="admin-control" :label="__('Assign this many days before it is due')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input type="date" wire:model="form.effective_from" class="admin-control" :label="__('Effective from')" />
                <flux:input type="date" wire:model="form.effective_until" class="admin-control" :label="__('Effective until')" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:button x-on:click="$wire.editing = false" variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="admin-primary-action">{{ __('Save requirement') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="scheduleRequirementId" class="max-w-4xl">
        @if ($scheduledRequirement)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Upcoming schedule') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Next 3 months for :requirement. This preview does not create assignments.', ['requirement' => $scheduledRequirement->name]) }}</flux:text>
                </div>
                @if ($scheduleRows->isEmpty())
                    <x-empty-state icon="calendar-days" :title="__('No upcoming occurrences')" :description="__('No eligible people or occurrences were found in the next 3 months.')" />
                @else
                    <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7]">
                        <table class="w-full text-left text-sm">
                            <thead><tr><th>{{ __('Person') }}</th><th>{{ __('Cycle') }}</th><th>{{ __('Available') }}</th><th>{{ __('Due date') }}</th><th>{{ __('Status') }}</th></tr></thead>
                            <tbody>
                                @foreach ($scheduleRows as $row)
                                    <tr class="border-t" wire:key="schedule-{{ $row['person_id'] }}-{{ $row['cycle'] }}">
                                        <td class="font-semibold text-[#262d33]">{{ $row['person_name'] }}</td>
                                        <td>{{ $row['cycle'] }}</td>
                                        <td>{{ $row['available_at']?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</td>
                                        <td>{{ $row['due_at']->locale(app()->getLocale())->translatedFormat('M j, Y') }}</td>
                                        <td><span class="status-pill {{ $row['materialized'] ? 'status-pill--accent' : 'status-pill--neutral' }}">{{ $row['status'] }}{{ $row['estimated'] ? ' · '.__('Estimated') : '' }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div>{{ $scheduleRows->links() }}</div>
                @endif
                <p class="text-xs text-[#6f797f]">{{ __('Dates based on completion are estimates until the previous training is completed.') }}</p>
                <div class="flex justify-end"><flux:button wire:click="$set('scheduleRequirementId', null)" variant="ghost">{{ __('Close') }}</flux:button></div>
            </div>
        @endif
    </flux:modal>

    {{-- Target form --}}
    <flux:modal wire:model.self="targeting" @close="closeTargeting" class="max-w-lg">
        <form wire:submit="addTarget" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Add audience target') }}</flux:heading>
                <flux:text class="mt-2">{{ __('ui.target_form_help') }}</flux:text>
            </div>

            <flux:select wire:model.live="targetForm.scope_type" class="admin-control" :label="__('Scope')">
                @foreach (App\Enums\TargetScope::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </flux:select>

            @if (in_array($targetForm['scope_type'] ?? '', ['department', 'department_job_function'], true))
                <flux:select wire:model="targetForm.department_id" class="admin-control" :label="__('Department')">
                    <option value="">{{ __('Select a department') }}</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </flux:select>
            @endif

            @if (in_array($targetForm['scope_type'] ?? '', ['job_function', 'department_job_function'], true))
                <fieldset class="min-w-0 space-y-3" x-data="{
                    functions: @js($jobFunctions->map(fn ($function) => ['id' => (string) $function->id, 'name' => mb_strtolower($function->name)])->values()),
                    get search() { return $wire.targetSearch.toLocaleLowerCase().trim() },
                    get matchingIds() { return this.functions.filter(item => item.name.includes(this.search)).map(item => item.id) },
                    get selectedIds() { return ($wire.targetForm.job_function_ids ?? []).map(String) },
                    get allSelected() { return this.matchingIds.length > 0 && this.matchingIds.every(id => this.selectedIds.includes(id)) },
                    get partiallySelected() { return !this.allSelected && this.matchingIds.some(id => this.selectedIds.includes(id)) },
                    toggleMatching() {
                        const matching = this.matchingIds;
                        if (!matching.length) return;
                        $wire.$set('targetForm.job_function_ids', this.allSelected
                            ? this.selectedIds.filter(id => !matching.includes(id))
                            : [...new Set([...this.selectedIds, ...matching])]);
                    }
                }">
                    <legend class="text-sm font-medium text-[var(--ds-text-primary)]">{{ __('Job functions') }}</legend>
                    <flux:input id="target-function-search" name="targetSearch" label:for="target-function-search" x-model="$wire.targetSearch" :label="__('Search job functions')" class="admin-control" />
                    <p class="text-sm text-[var(--ds-text-secondary)]" role="status">{{ __(':count selected', ['count' => count($targetForm['job_function_ids'] ?? [])]) }}</p>
                    <flux:field variant="inline" class="min-h-11 sm:min-h-8 !grid-cols-[auto_minmax(0,1fr)] items-center">
                        {{-- Flux protects ARIA attributes; its indeterminate setter updates only the icon. --}}
                        <flux:checkbox id="target-functions-all" x-bind:disabled="matchingIds.length === 0"
                            x-bind:aria-disabled="String(matchingIds.length === 0)"
                            x-effect="$el.checked = allSelected; $el.indeterminate = partiallySelected; $el._durableAttributeObserver.pause(() => $el.setAttribute('aria-checked', partiallySelected ? 'mixed' : String(allSelected)))"
                            @change="toggleMatching()" aria-describedby="target-functions-error" />
                        <flux:label class="min-w-0 break-words whitespace-normal">
                            <span x-text="search ? @js(__('Select all results')) : @js(__('Select all'))">{{ __('Select all') }}</span>
                        </flux:label>
                    </flux:field>
                    <div class="max-h-48 overflow-y-auto rounded-xl border border-[var(--ds-border-default)] p-2">
                        @foreach ($jobFunctions as $jobFunction)
                            <div wire:key="target-function-{{ $jobFunction->id }}" data-target-function="{{ $jobFunction->id }}"
                                x-show="@js(mb_strtolower($jobFunction->name)).includes($wire.targetSearch.toLocaleLowerCase().trim())">
                                <flux:field variant="inline" class="min-h-11 sm:min-h-8 !grid-cols-[auto_minmax(0,1fr)] items-center">
                                    <flux:checkbox wire:model.live="targetForm.job_function_ids" value="{{ $jobFunction->id }}"
                                        aria-describedby="target-functions-error" />
                                    <flux:label class="min-w-0 break-words whitespace-normal">{{ $jobFunction->name }}</flux:label>
                                </flux:field>
                            </div>
                        @endforeach
                        <p x-show="! @js($jobFunctions->pluck('name')->map(fn ($name) => mb_strtolower($name))->values()).some(name => name.includes($wire.targetSearch.toLocaleLowerCase().trim()))"
                            class="text-sm text-[var(--ds-text-secondary)]">{{ __('No job functions found.') }}</p>
                    </div>
                    <div id="target-functions-error">
                        <flux:error name="targetForm.job_function_ids" />
                        <flux:error name="targetForm.job_function_ids.*" />
                    </div>
                </fieldset>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button wire:click="closeTargeting" variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" wire:loading.attr="disabled" wire:target="addTarget" variant="primary" class="admin-primary-action">{{ __('Add target') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
