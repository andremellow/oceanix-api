<?php

use App\Actions\Courses\AssociateSharedCourse;
use App\Actions\Courses\CreateDraftFromVersion;
use App\Actions\Courses\RemoveSharedCourse;
use App\Enums\CourseVersionStatus;
use App\Enums\Permission;
use App\Exceptions\CoursePublicationException;
use App\Models\Company;
use App\Models\CompanyCourse;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Services\SharedContent\SharedContentCatalog;
use App\Tenancy\TenantContext;
use Livewire\Component;

new class extends Component
{
    public Course $course;

    public Company $company;

    public bool $catalogView = false;

    public bool $confirmingRemoval = false;

    public string $removalReason = '';

    public ?int $selectedVersionId = null;

    public function mount(Company $company, Course $course): void
    {
        $this->authorize('view', $course);

        $tenant = $company->exists ? $company : app(TenantContext::class)->get();
        abort_unless($tenant instanceof Company, 404);
        $this->company = $tenant;
        $this->catalogView = request()->routeIs('shared-courses.show');

        if ($course->is_shared && ! $this->catalogView) {
            abort_unless(CompanyCourse::query()->where('course_id', $course->id)->active()->exists(), 404);
        }

        $this->course = $course;
        $this->selectedVersionId = $course->current_published_version_id
            ?? $course->versions()->first()?->id;
    }

    public function add(AssociateSharedCourse $action): void
    {
        abort_unless($this->course->is_shared, 404);
        $action->handle($this->course, auth()->user());
        session()->flash('status', __('Shared course added to your company.'));
    }

    public function remove(RemoveSharedCourse $action, SharedContentCatalog $catalog): void
    {
        abort_unless($this->course->is_shared, 404);
        $this->validate(['removalReason' => ['required', 'string', 'max:1000']]);
        $association = $catalog->associationFor($this->company, $this->course);
        abort_unless($association instanceof CompanyCourse && $association->removed_at === null, 404);

        try {
            $action->handle($association, auth()->user(), $this->removalReason);
        } catch (\DomainException $exception) {
            $this->addError('removalReason', $exception->getMessage());

            return;
        }

        $this->confirmingRemoval = false;
        session()->flash('status', __('Shared course removed from your company.'));
    }

    public function selectVersion(int $versionId): void
    {
        // Never trust an id arriving from public Livewire state: it must belong to this course.
        abort_unless($this->course->versions()->whereKey($versionId)->exists(), 404);

        $this->selectedVersionId = $versionId;
    }

    /** Editing a published version means cloning it into a new draft — see §6 of the spec. */
    public function createDraft(CreateDraftFromVersion $action): void
    {
        $this->authorize('update', $this->course);

        $source = $this->course->currentPublishedVersion
            ?? $this->course->versions()->orderByDesc('version_number')->firstOrFail();

        try {
            $draft = $action->handle($source);
        } catch (CoursePublicationException $e) {
            session()->flash('status', $e->problems[0]);

            return;
        }

        $this->redirect(route('courses.editor', ['course' => $this->course]), navigate: true);
    }

    public function with(SharedContentCatalog $catalog): array
    {
        $version = $this->selectedVersionId === null ? null : CourseVersion::query()
            ->with(['lessons.video', 'lessons.questions.options', 'moduleCompositions.moduleVersion.video', 'moduleCompositions.moduleVersion.questions.options'])
            ->find($this->selectedVersionId);
        $displayLessons = $version?->moduleCompositions->isNotEmpty()
            ? $version->moduleCompositions->map(fn ($composition) => $composition->moduleVersion)->filter()
            : ($version?->lessons ?? collect());

        return [
            'versions' => $this->course->versions()->with('publisher')->get(),
            'version' => $version,
            'displayLessons' => $displayLessons,
            'association' => $this->course->is_shared ? $catalog->associationFor($this->company, $this->course) : null,
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="$course->code"
        :title="$course->title"
        :description="$course->description">
        <span class="status-pill {{ $course->status->pillModifier() }}">{{ $course->status->label() }}</span>
        @if ($course->is_shared)
            <span class="status-pill status-pill--accent">{{ __('Shared') }}</span>
            <span class="status-pill status-pill--neutral">{{ __('Managed by platform') }}</span>
        @endif
        <flux:button :href="$catalogView ? route('shared-courses.index', ['company' => $company]) : route('courses.index', ['company' => $company])" wire:navigate variant="ghost" size="sm">{{ $catalogView ? __('Back to shared courses') : __('ui.back_to_courses') }}</flux:button>
        @if ($course->is_shared)
            @if ($association?->removed_at === null && $association !== null)
                @can(Permission::SharedCoursesRemove->value)
                    <flux:button wire:click="$set('confirmingRemoval', true)" variant="danger">{{ __('Remove from Company') }}</flux:button>
                @endcan
            @else
                @can(Permission::SharedCoursesAdd->value)
                    <flux:button wire:click="add" wire:loading.attr="disabled" variant="primary">{{ __('Add to Company') }}</flux:button>
                @endcan
            @endif
        @endif
        @can('update', $course)
            @if ($course->versions()->where('status', CourseVersionStatus::Draft->value)->exists())
                <flux:button :href="route('courses.editor', ['course' => $course])" wire:navigate variant="primary" class="admin-primary-action">{{ __('Edit draft') }}</flux:button>
            @else
                <flux:button wire:click="createDraft" variant="primary" class="admin-primary-action">{{ __('New draft version') }}</flux:button>
            @endif
        @endcan
    </x-page-hero>

    <x-status-message />

    <div class="grid gap-5 lg:grid-cols-[280px_minmax(0,1fr)]">
        <section class="detail-card">
            <span class="detail-card-icon"><flux:icon.rectangle-stack class="size-5" /></span>
            <h2 class="detail-card-title">{{ __('Versions') }}</h2>
            <p class="mt-1 text-sm text-[#6f797f]">{{ __('ui.versions_help') }}</p>

            <div class="mt-5 space-y-2">
                @foreach ($versions as $item)
                    <button type="button" wire:click="selectVersion({{ $item->id }})"
                        class="role-option w-full text-left {{ $item->id === $selectedVersionId ? 'is-selected' : '' }}">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-bold text-[#262d33]">{{ __('Version :number', ['number' => $item->version_number]) }}</span>
                            <span class="mt-0.5 block text-xs text-[#8a9298]">
                                {{ $item->published_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? __('Not published') }}
                            </span>
                        </span>
                        <span class="status-pill {{ $item->status->pillModifier() }}">{{ $item->status->label() }}</span>
                    </button>
                @endforeach
            </div>
        </section>

        <div class="space-y-5">
            @if ($version === null)
                <x-empty-state
                    icon="document-plus"
                    :title="__('ui.no_versions')"
                    :description="__('ui.no_versions_help')" />
            @else
                <section class="detail-card">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <span class="detail-card-icon"><flux:icon.film class="size-5" /></span>
                            <h2 class="detail-card-title">{{ $version->title }}</h2>
                            <p class="mt-1 text-sm text-[#6f797f]">
                                {{ trans_choice('ui.lessons_count', $displayLessons->count(), ['count' => $displayLessons->count()]) }}
                                · {{ __(':count min', ['count' => $version->estimatedMinutes()]) }}
                            </p>
                        </div>
                        @if (! $version->isEditable())
                            <span class="status-pill status-pill--neutral">{{ __('ui.immutable') }}</span>
                        @endif
                    </div>

                    <div class="mt-6 space-y-4">
                        @forelse ($displayLessons as $lesson)
                            <div class="rounded-[18px] border border-[#e4e9ec] bg-[#f8fafb] p-4 sm:p-5">
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="grid size-9 place-items-center rounded-xl bg-white text-sm font-bold text-[#1c6b84] shadow-sm ring-1 ring-[#dfe7eb]">{{ $lesson->position }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-semibold text-[#262d33]">{{ $lesson->title }}</p>
                                        <p class="mt-0.5 text-xs text-[#8a9298]">
                                            {{ $lesson->video?->formattedDuration() ?? __('No video') }}
                                            · {{ __('ui.watch_threshold', ['percentage' => $lesson->minimum_watch_percentage]) }}
                                            · {{ __('ui.passing_score', ['score' => $lesson->passing_score]) }}
                                        </p>
                                    </div>
                                    @if ($lesson->video)
                                        <span class="status-pill {{ $lesson->video->status->pillModifier() }}">{{ $lesson->video->status->label() }}</span>
                                    @endif
                                    @unless ($lesson->is_required)
                                        <span class="status-pill status-pill--neutral">{{ __('Optional') }}</span>
                                    @endunless
                                </div>

                                @if ($lesson->questions->isNotEmpty())
                                    <ul class="mt-4 space-y-2 border-t border-[#e4e9ec] pt-4">
                                        @foreach ($lesson->questions as $question)
                                            <li class="text-sm text-[#5f6a71]">
                                                <span class="font-semibold text-[#3d464c]">{{ $question->position }}.</span>
                                                {{ $question->prompt }}
                                                <span class="ml-1 text-xs text-[#8a9298]">({{ $question->type->label() }} · {{ trans_choice('ui.attempts_allowed', $question->max_attempts, ['count' => $question->max_attempts]) }})</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @empty
                            <x-empty-state
                                icon="film"
                                :title="__('ui.no_lessons')"
                                :description="__('ui.no_lessons_help')" />
                        @endforelse
                    </div>
                </section>
            @endif
        </div>
    </div>

    <flux:modal wire:model.self="confirmingRemoval" class="max-w-lg">
        <form wire:submit="remove" class="space-y-5">
            <div><flux:heading size="lg">{{ __('Remove shared course?') }}</flux:heading><flux:text class="mt-2">{{ __('Removal is blocked while requirements or open assignments depend on this course.') }}</flux:text></div>
            <flux:textarea wire:model="removalReason" :label="__('Reason')" required />
            <div class="flex justify-end gap-2"><flux:button type="button" wire:click="$set('confirmingRemoval', false)" variant="ghost">{{ __('Cancel') }}</flux:button><flux:button type="submit" wire:loading.attr="disabled" variant="danger">{{ __('Remove from Company') }}</flux:button></div>
        </form>
    </flux:modal>
</div>
