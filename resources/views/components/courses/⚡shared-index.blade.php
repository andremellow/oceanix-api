<?php

use App\Actions\Courses\AssociateSharedCourse;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\Company;
use App\Services\SharedContent\SharedContentCatalog;
use App\Tenancy\TenantContext;
use Livewire\Component;

new class extends Component
{
    public Company $company;

    public string $search = '';

    public function mount(Company $company): void
    {
        $tenant = $company->exists ? $company : app(TenantContext::class)->get();
        abort_unless($tenant instanceof Company, 404);
        $this->company = $tenant;
    }

    public function add(int $courseId, AssociateSharedCourse $action): void
    {
        $course = Course::query()->shared()->findOrFail($courseId);
        $action->handle($course, auth()->user());
        session()->flash('status', __('Shared course added to your company.'));
    }

    public function with(SharedContentCatalog $catalog): array
    {
        return ['courses' => $catalog->eligibleCourses($this->company, $this->search ?: null)];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero :kicker="__('Courses')" :title="__('Browse Shared Courses')" :description="__('Add platform-managed courses to your company without copying their content.')">
        <flux:button :href="route('courses.index', ['company' => $company])" wire:navigate variant="ghost">{{ __('Back to courses') }}</flux:button>
    </x-page-hero>
    <x-status-message />
    <div class="form-panel rounded-[20px] border border-[#dde3e7] p-4 sm:p-5">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :label="__('Search')" :placeholder="__('Course title or code')" />
    </div>
    @if ($courses->isEmpty())
        <x-empty-state icon="book-open" :title="__('No shared courses available')" :description="__('New platform-managed courses will appear here when they are published.')" />
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($courses as $course)
                <section class="saas-feature-card flex flex-col">
                    <div class="flex items-start justify-between gap-3"><span class="saas-feature-icon"><flux:icon.book-open class="size-5" /></span><span class="status-pill status-pill--accent">{{ __('Shared') }}</span></div>
                    <a href="{{ route('shared-courses.show', ['company' => $company, 'course' => $course]) }}" wire:navigate class="mt-5 font-bold text-[#262d33] hover:text-[#1c6b84]">{{ $course->title }}</a>
                    <p class="mt-1 text-xs font-bold uppercase tracking-wider text-[#8a9298]">{{ $course->code }}</p>
                    <p class="mt-2 line-clamp-2 text-sm text-[#6f797f]">{{ $course->description ?: __('No description provided') }}</p>
                    <p class="mt-4 text-xs font-semibold text-[#1c6b84]">{{ __('Managed by platform') }}</p>
                    @can(Permission::SharedCoursesAdd->value)
                        <flux:button wire:click="add({{ $course->id }})" wire:loading.attr="disabled" class="mt-4" variant="primary">{{ __('Add to Company') }}</flux:button>
                    @endcan
                </section>
            @endforeach
        </div>
    @endif
</div>
