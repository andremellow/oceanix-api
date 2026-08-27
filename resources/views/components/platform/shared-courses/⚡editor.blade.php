<?php

use App\Actions\Courses\PublishCourseVersion;
use App\Enums\CourseVersionStatus;
use App\Exceptions\CoursePublicationException;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Services\Platform\PlatformAccess;
use App\Services\SharedContent\SharedContentCatalog;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    public Course $course;

    public CourseVersion $version;

    public bool $confirmingPublish = false;

    public bool $restartInProgress = false;

    public function mount(Course $course, PlatformAccess $access): void
    {
        $access->authorize();
        abort_unless($course->is_shared && $course->company_id === null, 404);
        $this->course = $course;
        $this->version = $course->versions()->where('status', CourseVersionStatus::Draft->value)->firstOrFail();
    }

    public function confirmPublish(PlatformAccess $access): void
    {
        $access->authorize();
        $this->restartInProgress = false;
        $this->confirmingPublish = true;
    }

    public function publish(PublishCourseVersion $action, PlatformAccess $access): void
    {
        $actor = $access->authorize();

        try {
            $action->handle(
                $this->version,
                $actor,
                $this->restartInProgress,
            );
        } catch (CoursePublicationException $exception) {
            $this->addError('publish', $exception->problems[0] ?? __('The course could not be published.'));
            $this->confirmingPublish = false;

            return;
        }

        session()->flash('status', __('Version :number published', ['number' => $this->version->version_number]));
        $this->redirect(route('platform.shared-courses.show', ['course' => $this->course]), navigate: true);
    }

    public function with(SharedContentCatalog $catalog, PlatformAccess $access): array
    {
        $access->authorize();

        return [
            'impact' => $catalog->coursePublicationImpact($this->course),
            'modules' => $this->version->moduleCompositions()->with(['moduleVersion.video', 'moduleVersion.questions.options'])->get(),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero :kicker="__('Shared course draft')" :title="$version->title" :description="__('Review the immutable module snapshot and publish it for every associated company.')">
        <span class="status-pill status-pill--accent">{{ __('Shared') }}</span>
        <flux:button :href="route('platform.shared-courses.show', ['course' => $course])" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
        <flux:button wire:click="confirmPublish" wire:loading.attr="disabled" variant="primary" class="admin-primary-action">{{ __('Review and publish') }}</flux:button>
    </x-page-hero>

    @error('publish') <flux:callout variant="danger" :heading="$message" /> @enderror

    <section class="detail-card">
        <h2 class="detail-card-title">{{ __('Course modules') }}</h2>
        <p class="mt-1 text-sm text-[#6f797f]">{{ __('Published module versions are frozen in this course version.') }}</p>
        <div class="mt-5 space-y-3">
            @forelse ($modules as $composition)
                <div class="rounded-[18px] border border-[#e4e9ec] bg-[#f8fafb] p-4"><p class="font-semibold">{{ $composition->moduleVersion->title }}</p><p class="text-xs text-[#8a9298]">{{ __('Version :number', ['number' => $composition->moduleVersion->version_number]) }}</p></div>
            @empty
                <x-empty-state icon="rectangle-stack" :title="__('No modules selected')" :description="__('Add at least one published module before publishing this course.')" />
            @endforelse
        </div>
    </section>

    <flux:modal wire:model.self="confirmingPublish" class="max-w-xl">
        <div class="space-y-5">
            <div><flux:heading size="lg">{{ __('Publish shared course?') }}</flux:heading><flux:text class="mt-2">{{ __('The new version automatically applies to people who have not started the course.') }}</flux:text></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <section class="metric-card metric-card--slate"><p class="metric-label">{{ __('Not started') }}</p><p class="metric-value">{{ $impact['not_started'] }}</p></section>
                <section class="metric-card metric-card--amber"><p class="metric-label">{{ __('In progress') }}</p><p class="metric-value">{{ $impact['in_progress'] }}</p></section>
            </div>
            <flux:checkbox wire:model="restartInProgress" :label="__('Restart in-progress assignments')" :description="__('People already in progress keep their current version unless this option is selected.')" />
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('confirmingPublish', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="publish" wire:loading.attr="disabled" variant="primary" class="admin-primary-action">{{ __('Publish version') }}</flux:button>
            </div>
            <p wire:loading wire:target="publish" role="status" class="text-sm text-[#5f6a71]">{{ __('Publishing and updating assignments…') }}</p>
        </div>
    </flux:modal>
</div>
