<?php

use App\Actions\Courses\CreateDraftFromVersion;
use App\Actions\Courses\DiscardSharedCourseDraft;
use App\Actions\Courses\PrepareSharedCourseEditor;
use App\Actions\SharedContent\ArchiveSharedContent;
use App\Enums\CourseStatus;
use App\Enums\CourseVersionStatus;
use App\Exceptions\CoursePublicationException;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Services\Platform\PlatformAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::platform')] class extends Component
{
    public Course $course;

    public ?Company $company = null;

    public ?int $selectedVersionId = null;

    public bool $confirmingArchive = false;

    public string $archiveReason = '';

    public bool $confirmingDiscard = false;

    public string $discardReason = '';

    public string $discardRevision = '';

    public function mount(Course $course, PlatformAccess $access, ?Company $company = null): void
    {
        $access->authorize();
        if ($company === null) {
            abort_unless($course->is_shared && $course->company_id === null, 404);
        } else {
            $isOwned = ! $course->is_shared && (int) $course->company_id === (int) $company->id;
            $isAssociated = $course->is_shared && $course->companyAssociations()
                ->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->active()
                ->exists();
            abort_unless($isOwned || $isAssociated, 404);
        }

        $this->course = $course;
        $this->company = $company;
        $this->selectedVersionId = $course->current_published_version_id ?? $course->versions()->first()?->id;
        $draft = $course->versions()->where('status', CourseVersionStatus::Draft->value)->where('publication_kind', 'manual')->first();
        $this->discardRevision = $draft === null ? '' : app(DiscardSharedCourseDraft::class)->revision($draft);
    }

    public function selectVersion(int $versionId, PlatformAccess $access): void
    {
        $access->authorize();
        abort_unless($this->course->versions()->whereKey($versionId)->exists(), 404);
        $this->selectedVersionId = $versionId;
    }

    public function createDraft(CreateDraftFromVersion $action, PlatformAccess $access): void
    {
        $account = $access->authorize();
        $source = $this->course->currentPublishedVersion ?? $this->course->versions()->firstOrFail();

        try {
            $draft = $action->handle($source, $account);
            $prepare = app(PrepareSharedCourseEditor::class);
            $prepare->handle($this->course, $account, $prepare->revision($this->course, $draft));
        } catch (CoursePublicationException $exception) {
            $this->addError('draft', $exception->problems[0] ?? __('A draft could not be created.'));

            return;
        }

        $this->redirect(route('platform.shared-courses.editor', ['course' => $this->course]), navigate: true);
    }

    public function editDraft(PrepareSharedCourseEditor $action, PlatformAccess $access): void
    {
        $account = $access->authorize();
        $draft = $this->course->manualDraftVersion() ?? abort(404);
        $action->handle($this->course, $account, $action->revision($this->course, $draft));
        $this->redirect(route('platform.shared-courses.editor', ['course' => $this->course]), navigate: true);
    }

    public function discardDraft(DiscardSharedCourseDraft $action, PlatformAccess $access): void
    {
        $account = $access->authorize();
        $this->validate(['discardReason' => ['required', 'string', 'max:500']]);
        $draft = $this->course->versions()->where('status', CourseVersionStatus::Draft->value)->where('publication_kind', 'manual')->firstOrFail();
        $discarded = $action->handle($draft, $account, $this->discardReason, $this->discardRevision);
        $this->selectedVersionId = $this->course->fresh()->current_published_version_id ?? $discarded->id;
        $this->reset('confirmingDiscard', 'discardReason', 'discardRevision');
        session()->flash('status', __('Draft discarded. Draft-only edits and associations were abandoned; shared modules and published versions remain available.'));
    }

    public function archive(ArchiveSharedContent $action, PlatformAccess $access): void
    {
        $account = $access->authorize();
        $this->validate(['archiveReason' => ['required', 'string', 'max:500']]);
        $this->course = $action->handle($this->course, $account, $this->archiveReason);
        $this->reset('confirmingArchive', 'archiveReason');
        session()->flash('status', __('Shared course archived.'));
    }

    public function with(PlatformAccess $access): array
    {
        $access->authorize();

        return [
            'versions' => $this->course->versions()->with('platformPublisher')->get(),
            'version' => $this->selectedVersionId === null ? null : CourseVersion::query()
                ->with(['moduleCompositions.moduleVersion.video', 'moduleCompositions.moduleVersion.questions.options'])
                ->find($this->selectedVersionId),
            'associationCount' => $this->course->companyAssociations()->whereNull('removed_at')->count(),
            'canDiscardDraft' => $access->account() !== null,
            'manualDraft' => $this->course->manualDraftVersion(),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero :kicker="$course->code" :title="$course->title" :description="$course->description ?: __('No description provided')" description-class="max-w-none">
        <span class="status-pill {{ $course->is_shared ? 'status-pill--accent' : 'status-pill--neutral' }}">{{ $course->is_shared ? __('Shared') : __('Company-owned') }}</span>
        <span class="status-pill {{ $course->status->pillModifier() }}">{{ $course->status->label() }}</span>
        <flux:button :href="$company ? route('platform.companies.show', ['company' => $company]) : route('platform.shared-courses.index')" variant="ghost" size="sm">{{ $company ? __('Back to company') : __('Back to shared courses') }}</flux:button>
        @if (! $course->is_shared)
            <span class="text-sm text-[#707a80]">{{ __('This course is managed by the company.') }}</span>
        @elseif ($course->status === CourseStatus::Archived)
            <span class="text-sm text-[#707a80]">{{ __('New associations and assignments are blocked.') }}</span>
        @elseif ($course->versions()->where('status', CourseVersionStatus::Draft->value)->where('publication_kind', 'manual')->exists())
            <flux:button wire:click="editDraft" wire:loading.attr="disabled" variant="primary">{{ __('Edit draft') }}</flux:button>
            @if ($canDiscardDraft)<flux:button wire:click="$set('confirmingDiscard', true)" variant="danger">{{ __('Discard draft') }}</flux:button>@endif
        @else
            <flux:button wire:click="createDraft" wire:loading.attr="disabled" variant="primary">{{ __('New draft version') }}</flux:button>
        @endif
        @if ($course->is_shared && $course->status !== CourseStatus::Archived)
            <flux:button wire:click="$set('confirmingArchive', true)" variant="danger">{{ __('Archive shared course') }}</flux:button>
        @endif
    </x-page-hero>

    @error('draft') <flux:callout variant="danger" :heading="$message" /> @enderror
    <x-status-message />

    <div class="grid gap-4 sm:grid-cols-3">
        <section class="metric-card metric-card--teal"><p class="metric-label">{{ __('Ownership') }}</p><p class="metric-value metric-value--text">{{ $course->is_shared ? __('Managed by platform') : __('Managed by company') }}</p></section>
        <section class="metric-card metric-card--slate"><p class="metric-label">{{ __('Companies') }}</p><p class="metric-value">{{ $associationCount }}</p></section>
        <section class="metric-card metric-card--violet"><p class="metric-label">{{ __('Versions') }}</p><p class="metric-value">{{ $versions->count() }}</p></section>
    </div>

    <div class="grid gap-5 lg:grid-cols-[280px_minmax(0,1fr)]">
        <section class="detail-card">
            <h2 class="detail-card-title">{{ __('Versions') }}</h2>
            <div class="mt-4 space-y-2">
                @foreach ($versions as $item)
                    <button type="button" wire:click="selectVersion({{ $item->id }})" class="role-option w-full text-left {{ $item->id === $selectedVersionId ? 'is-selected' : '' }}">
                        <span class="min-w-0 flex-1"><span class="block text-sm font-bold">{{ __('Version :number', ['number' => $item->version_number]) }}</span><span class="block text-xs text-[#8a9298]">{{ $item->published_at?->translatedFormat('M j, Y') ?? __('Not published') }}</span></span>
                        <span class="status-pill {{ $item->status->pillModifier() }}">{{ $item->status->label() }}</span>
                    </button>
                @endforeach
            </div>
        </section>

        <section class="detail-card">
            @if ($version)
                <h2 class="detail-card-title">{{ $version->title }}</h2>
                <p class="mt-1 text-sm text-[#6f797f]">{{ $version->description ?: __('No description provided') }}</p>
                <div class="mt-5 space-y-3">
                    @forelse ($version->moduleCompositions as $composition)
                        <div class="rounded-[18px] border border-[#e4e9ec] bg-[#f8fafb] p-4">
                            <div class="flex items-center justify-between gap-3"><div><p class="font-semibold">{{ $composition->moduleVersion->title }}</p><p class="text-xs text-[#8a9298]">{{ __('Version :number', ['number' => $composition->moduleVersion->version_number]) }}</p></div>@unless ($composition->is_required)<span class="status-pill status-pill--neutral">{{ __('Optional') }}</span>@endunless</div>
                        </div>
                    @empty
                        <x-empty-state icon="rectangle-stack" :title="__('No modules in this version')" :description="__('Open the draft editor to compose this course.')" />
                    @endforelse
                </div>
            @else
                <x-empty-state icon="document-plus" :title="__('No versions yet')" :description="__('Create a draft to begin authoring this shared course.')" />
            @endif
        </section>
    </div>

    <flux:modal wire:model.self="confirmingArchive" class="max-w-lg">
        <form wire:submit="archive" class="space-y-5">
            <div><flux:heading size="lg">{{ __('Archive shared course?') }}</flux:heading><flux:text class="mt-2">{{ __('Companies cannot add this course or create new assignments. Existing assignments and evidence remain available.') }}</flux:text></div>
            <flux:textarea wire:model="archiveReason" :label="__('Reason')" required />
            <div class="flex justify-end gap-2"><flux:button type="button" wire:click="$set('confirmingArchive', false)" variant="ghost">{{ __('Cancel') }}</flux:button><flux:button type="submit" wire:loading.attr="disabled" variant="danger">{{ __('Archive shared course') }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="confirmingDiscard" :dismissible="false" class="max-w-lg">
        <form wire:submit="discardDraft" class="space-y-5">
            <div><flux:heading size="lg">{{ __('Discard this draft?') }}</flux:heading><flux:text class="mt-2">{{ __('Draft-only edits and associations will be abandoned. Shared modules and published versions remain available, and the discarded composition is preserved in the audit trail.') }}</flux:text></div>
            @if ($manualDraft)
                <div class="rounded-[18px] border border-[#dde3e7] bg-[#f7f9fa] p-4">
                    <p class="font-bold break-words">{{ $course->code }} · {{ $course->title }}</p>
                    <p class="mt-1 text-sm text-[#6f797f]">{{ __('Version :number', ['number' => $manualDraft->version_number]) }} · {{ $manualDraft->status->label() }}</p>
                </div>
            @endif
            <flux:textarea wire:model="discardReason" :label="__('Reason')" required />
            @error('discard') <flux:callout variant="danger" :heading="$message" /> @enderror
            <p wire:loading wire:target="discardDraft" class="text-sm text-[#59656b]" role="status" aria-live="polite">{{ __('Discarding draft…') }}</p>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><flux:button class="w-full whitespace-normal sm:w-auto" type="button" wire:click="$set('confirmingDiscard', false)" wire:loading.attr="disabled" wire:target="discardDraft" variant="ghost">{{ __('Cancel') }}</flux:button><flux:button class="w-full whitespace-normal sm:w-auto" type="submit" wire:loading.attr="disabled" wire:target="discardDraft" variant="danger"><span wire:loading.remove wire:target="discardDraft">{{ __('Discard draft') }}</span><span wire:loading wire:target="discardDraft">{{ __('Discarding draft…') }}</span></flux:button></div>
        </form>
    </flux:modal>
</div>
