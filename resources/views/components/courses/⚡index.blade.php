<?php

use App\Enums\CourseStatus;
use App\Models\Course;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $query = Course::query()
            ->with(['currentPublishedVersion', 'versions'])
            ->withCount('assignments');

        if ($this->search !== '') {
            $term = '%'.strtolower($this->search).'%';
            $query->where(fn ($scoped) => $scoped
                ->whereRaw('lower(title) like ?', [$term])
                ->orWhereRaw('lower(code) like ?', [$term]));
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return ['courses' => $query->orderBy('title')->paginate(12)];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.content')"
        :title="__('Courses')"
        :description="__('ui.courses_page_description')">
        @can('create', App\Models\Course::class)
            <flux:button variant="primary" class="admin-primary-action" disabled>{{ __('New course') }}</flux:button>
        @endcan
    </x-page-hero>

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

    @if ($courses->isEmpty())
        <x-empty-state
            icon="book-open"
            :title="__('ui.no_courses')"
            :description="__('ui.no_courses_help')" />
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($courses as $course)
                <a href="{{ route('courses.show', $course) }}" wire:navigate class="saas-feature-card group flex flex-col">
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

        <div>{{ $courses->links() }}</div>
    @endif
</div>
