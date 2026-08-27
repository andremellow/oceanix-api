<?php

use App\Actions\Courses\CreateCourse;
use App\Models\Course;
use App\Services\Platform\PlatformAccess;
use App\Services\SharedContent\SharedContentCatalog;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::platform')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $creating = false;

    public string $code = '';

    public string $title = '';

    public string $description = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(CreateCourse $action, PlatformAccess $access): void
    {
        $actor = $access->authorize();
        $data = $this->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('courses', 'code')->whereNull('company_id')],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $course = $action->handle(
            $data['code'],
            $data['title'],
            $data['description'] ?: null,
            platformActor: $actor,
        );

        $this->redirect(route('platform.shared-courses.editor', ['course' => $course]), navigate: true);
    }

    public function with(SharedContentCatalog $catalog, PlatformAccess $access): array
    {
        $access->authorize();

        return ['courses' => $catalog->platformCourses($this->search ?: null)];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero :kicker="__('Platform administration')" :title="__('Shared courses')" :description="__('Create and manage courses available across companies.')">
        <span class="status-pill status-pill--accent">{{ trans_choice('ui.results_count', $courses->count(), ['count' => $courses->count()]) }}</span>
        <flux:button wire:click="$set('creating', true)" variant="primary" class="admin-primary-action">{{ __('New shared course') }}</flux:button>
    </x-page-hero>

    <x-status-message />

    <div class="form-panel rounded-[20px] border border-[#dde3e7] p-4 sm:p-5">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :label="__('Search')" :placeholder="__('Course title or code')" />
    </div>

    @if ($courses->isEmpty())
        <x-empty-state icon="book-open" :title="__('No shared courses yet')" :description="__('Create the first course that companies can add to their training library.')" />
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($courses as $course)
                <a href="{{ route('platform.shared-courses.show', ['course' => $course]) }}" wire:navigate class="saas-feature-card group flex flex-col">
                    <div class="flex items-start justify-between gap-3">
                        <span class="saas-feature-icon bg-[#e4f0f5] text-[#1c6b84]"><flux:icon.book-open class="size-5" /></span>
                        <div class="flex flex-wrap justify-end gap-2">
                            <span class="status-pill status-pill--accent">{{ __('Shared') }}</span>
                            <span class="status-pill {{ $course->status->pillModifier() }}">{{ $course->status->label() }}</span>
                        </div>
                    </div>
                    <span class="mt-5 text-[11px] font-bold uppercase tracking-[.12em] text-[#8a9298]">{{ $course->code }}</span>
                    <span class="mt-1 text-base font-bold text-[#262d33]">{{ $course->title }}</span>
                    <span class="mt-1.5 line-clamp-2 text-sm leading-5 text-[#778188]">{{ $course->description ?: __('No description provided') }}</span>
                    <span class="mt-5 border-t border-[#eef1f4] pt-4 text-xs font-semibold text-[#1c6b84]">{{ __('Managed by platform') }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <flux:modal wire:model.self="creating" class="max-w-lg">
        <form wire:submit="create" class="space-y-5">
            <div><flux:heading size="lg">{{ __('New shared course') }}</flux:heading><flux:text class="mt-2">{{ __('Companies can add the published course without copying it.') }}</flux:text></div>
            <div class="grid gap-4 sm:grid-cols-[160px_minmax(0,1fr)]">
                <flux:input wire:model="code" :label="__('Code')" required />
                <flux:input wire:model="title" :label="__('Title')" required />
            </div>
            <flux:textarea wire:model="description" :label="__('Description')" rows="3" />
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('creating', false)" type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" wire:loading.attr="disabled" variant="primary" class="admin-primary-action">{{ __('Create and open editor') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
