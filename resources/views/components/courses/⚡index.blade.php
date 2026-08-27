<?php

use App\Actions\Courses\CreateCourse;
use App\Enums\CourseStatus;
use App\Models\Company;
use App\Models\Course;
use App\Services\Courses\CompanyCourseLibrary;
use App\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public bool $creating = false;

    public string $code = '';

    public string $title = '';

    public string $description = '';

    public Company $company;

    public function mount(Company $company): void
    {
        $tenant = $company->exists ? $company : app(TenantContext::class)->get();
        abort_unless($tenant instanceof Company, 404);
        $this->company = $tenant;
    }

    /** Creates the course and its first draft, then opens the editor on it. */
    public function create(CreateCourse $action): void
    {
        $this->authorize('create', Course::class);

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('courses', 'code')],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $course = $action->handle($validated['code'], $validated['title'], $validated['description'] ?: null);

        $this->redirect(route('courses.editor', ['course' => $course]), navigate: true);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function with(CompanyCourseLibrary $library): array
    {
        return [
            'courses' => $library->companyCourses($this->company, $this->search ?: null, $this->status ?: null),
            'sharedCourses' => $library->sharedCourses($this->company, $this->search ?: null),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.content')"
        :title="__('Courses')"
        :description="__('ui.courses_page_description')">
        <span class="status-pill status-pill--accent">{{ trans_choice('ui.results_count', $courses->count() + $sharedCourses->count(), ['count' => $courses->count() + $sharedCourses->count()]) }}</span>
        @can('create', App\Models\Course::class)
            <flux:button wire:click="$set('creating', true)" variant="primary" class="admin-primary-action">{{ __('New course') }}</flux:button>
        @endcan
    </x-page-hero>

    <x-status-message />

    @can(App\Enums\Permission::SharedCoursesView->value)
        <div class="flex justify-end"><flux:button :href="route('shared-courses.index', ['company' => $company])" wire:navigate variant="ghost">{{ __('Browse Shared Courses') }}</flux:button></div>
    @endcan

    <div class="form-panel rounded-[20px] border border-[#dde3e7] p-4 sm:p-5">
        <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_200px]">
            <flux:input
                wire:model.live.debounce.400ms="search"
                class="admin-control"
                icon="magnifying-glass"
                :label="__('Search')"
                :placeholder="__('Course title or code')" />
            <flux:select wire:model.live="status" class="admin-control" :label="__('Status')">
                <option value="">{{ __('All statuses') }}</option>
                @foreach (CourseStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <h2 class="text-xl font-bold tracking-tight">{{ __('Company Courses') }}</h2>
    @if ($courses->isEmpty())
        <x-empty-state
            icon="book-open"
            :title="__('ui.no_courses')"
            :description="__('ui.no_courses_help')" />
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($courses as $course)
                <a href="{{ route('courses.show', ['course' => $course]) }}" wire:navigate class="saas-feature-card group flex flex-col">
                    <div class="flex items-start justify-between gap-3">
                        <span class="saas-feature-icon bg-[#e4f0f5] text-[#1c6b84]"><flux:icon.book-open class="size-5" /></span>
                        <span class="status-pill {{ $course->status->pillModifier() }}">{{ $course->status->label() }}</span>
                    </div>
                    <span class="mt-5 block text-[11px] font-bold uppercase tracking-[.12em] text-[#8a9298]">{{ $course->code }}</span>
                    <span class="mt-1 block text-base font-bold text-[#262d33]">{{ $course->title }}</span>
                    <span class="mt-1.5 line-clamp-2 block text-sm leading-5 text-[#778188]">{{ $course->description ?: __('No description provided') }}</span>
                    <div class="mt-5 flex items-center justify-between border-t border-[#eef1f4] pt-4 text-xs">
                        <span class="text-[#8a9298]">
                            @if ($course->currentPublishedVersion)
                                {{ __('Version :number published', ['number' => $course->currentPublishedVersion->version_number]) }}
                            @else
                                {{ __('No published version') }}
                            @endif
                        </span>
                        <span class="font-bold text-[#1c6b84]">{{ trans_choice('ui.assignments_count', $course->assignments_count, ['count' => $course->assignments_count]) }}</span>
                    </div>
                </a>
            @endforeach
        </div>

    @endif

    @if ($sharedCourses->isNotEmpty())
        <h2 class="text-xl font-bold tracking-tight">{{ __('Shared Courses') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($sharedCourses as $course)
                <a href="{{ route('shared-courses.show', ['company' => $company, 'course' => $course]) }}" wire:navigate class="saas-feature-card group flex flex-col">
                    <div class="flex items-start justify-between gap-3"><span class="saas-feature-icon"><flux:icon.book-open class="size-5" /></span><span class="status-pill status-pill--accent">{{ __('Shared') }}</span></div>
                    <span class="mt-5 text-[11px] font-bold uppercase tracking-[.12em] text-[#8a9298]">{{ $course->code }}</span>
                    <span class="mt-1 text-base font-bold text-[#262d33]">{{ $course->title }}</span>
                    <span class="mt-2 text-xs font-semibold text-[#1c6b84]">{{ __('Managed by platform') }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <flux:modal wire:model.self="creating" class="max-w-lg">
        <form wire:submit="create" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('New course') }}</flux:heading>
                <flux:text class="mt-2">{{ __('ui.new_course_help') }}</flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-[160px_minmax(0,1fr)]">
                <flux:input wire:model="code" class="admin-control" :label="__('Code')" placeholder="HUET-01" />
                <flux:input wire:model="title" class="admin-control" :label="__('Title')" :placeholder="__('Helicopter underwater escape')" />
            </div>
            <flux:textarea wire:model="description" class="admin-control" :label="__('Description')" rows="2" />

            <div class="flex justify-end gap-2">
                <flux:button x-on:click="$wire.creating = false" variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="admin-primary-action">{{ __('Create and open editor') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
