<?php

use App\Actions\Courses\MakeCourseShared;
use App\Models\Course;
use App\Services\Courses\CompanyCourseLibrary;
use App\Services\Courses\CoursePromotionImpact;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Oceanix\Platform\Models\Company;
use Oceanix\Platform\Services\Platform\PlatformAccess;

new class extends Component
{
    #[Locked]
    public int $companyId;

    public ?Course $course = null;

    public bool $confirmingPromotion = false;

    public ?string $promotionToken = null;

    public array $promotionModules = [];

    public array $affectedCourses = [];

    public function mount(int $companyId): void
    {
        $this->companyId = $companyId;
        $this->company();
    }

    public function boot(): void
    {
        app(PlatformAccess::class)->authorize();
    }

    private function company(): Company
    {
        app(PlatformAccess::class)->authorize();

        return Company::query()->findOrFail($this->companyId);
    }

    public function previewPromotion(int $courseId, CoursePromotionImpact $impact, PlatformAccess $access): void
    {
        $access->authorize();
        $course = Course::query()->withoutGlobalScopes()->findOrFail($courseId);

        try {
            $preview = $impact->preview($course, $this->company());
        } catch (DomainException $exception) {
            $this->addError('promotion', $exception->getMessage());

            return;
        }
        $this->course = $course;
        $this->promotionToken = $preview['token'];
        $this->promotionModules = $preview['modules']->where('is_shared', false)
            ->map(fn ($module): array => ['id' => $module->id, 'title' => $module->title])->values()->all();
        $this->affectedCourses = $preview['affected_courses']
            ->map(fn ($affected): array => ['id' => $affected->id, 'title' => $affected->title])->values()->all();
        $this->confirmingPromotion = true;
    }

    public function makeShared(MakeCourseShared $action, PlatformAccess $access): void
    {
        $access->authorize();
        abort_unless($this->course !== null && $this->promotionToken !== null, 404);

        try {
            $action->handle($this->course, $this->company(), $access->authorize(), $this->promotionToken);
        } catch (DomainException $exception) {
            $this->addError('promotion', $exception->getMessage());
            $this->confirmingPromotion = false;

            return;
        }

        session()->flash('status', __('Course promoted to the shared library.'));
        $this->course = null;
        $this->promotionToken = null;
        $this->promotionModules = [];
        $this->affectedCourses = [];
        $this->confirmingPromotion = false;
    }

    public function with(CompanyCourseLibrary $library): array
    {
        $company = $this->company();

        return ['company' => $company, 'courses' => $library->courses($company)];
    }
};
?>
<div class="space-y-5">
<x-oceanix-platform::status-message />
@error('promotion') <flux:callout variant="danger" :heading="$message" /> @enderror
    <section class="detail-card space-y-4">
        <div><h2 class="detail-card-title">{{ __('Courses') }}</h2><p class="mt-1 text-sm text-[#5f6a71]">{{ __('Browse company-owned courses and shared courses associated with this company.') }}</p></div>
        @if ($courses->isEmpty())
            <x-empty-state icon="book-open" :title="__('No courses')" :description="__('Company-owned and associated shared courses will appear here.')" />
        @else
            <div class="divide-y divide-[#e8edef]">
                @foreach ($courses as $companyCourse)
                    <div class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('platform.companies.courses.show', ['company' => $company, 'course' => $companyCourse]) }}" class="font-semibold text-[#262d33] hover:text-[#1c6b84]">{{ $companyCourse->title }}</a>
                                <span class="status-pill {{ $companyCourse->is_shared ? 'status-pill--accent' : 'status-pill--neutral' }}">{{ $companyCourse->is_shared ? __('Shared') : __('Company-owned') }}</span>
                            </div>
                            <p class="text-xs text-[#8a9298]">{{ $companyCourse->code }}</p>
                        </div>
                        @unless ($companyCourse->is_shared)
                            <flux:button wire:click="previewPromotion({{ $companyCourse->id }})" wire:loading.attr="disabled" variant="ghost">{{ __('Make Shared') }}</flux:button>
                        @endunless
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <flux:modal wire:model.self="confirmingPromotion" class="max-w-2xl">
        <div class="space-y-5">
            <div><flux:heading size="lg">{{ __('Make this course shared?') }}</flux:heading><flux:text class="mt-2">{{ __('The company will keep access, but editing moves permanently to platform administration.') }}</flux:text></div>
            <div class="rounded-[18px] border border-[#dde3e7] bg-[#f8fafb] p-4"><p class="text-xs font-bold uppercase tracking-wider text-[#8a9298]">{{ __('Source company') }}</p><p class="mt-1 font-semibold">{{ $company->name }}</p></div>
            <div><h3 class="font-bold">{{ __('Modules becoming shared') }}</h3><ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-[#5f6a71]">@forelse ($promotionModules as $module)<li>{{ $module['title'] }}</li>@empty<li>{{ __('No company-owned modules require transfer.') }}</li>@endforelse</ul></div>
            <div><h3 class="font-bold">{{ __('Other affected courses') }}</h3><ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-[#5f6a71]">@forelse ($affectedCourses as $affected)<li>{{ $affected['title'] }}</li>@empty<li>{{ __('No other courses use these modules.') }}</li>@endforelse</ul></div>
            <div class="flex justify-end gap-2"><flux:button wire:click="$set('confirmingPromotion', false)" variant="ghost">{{ __('Cancel') }}</flux:button><flux:button wire:click="makeShared" wire:loading.attr="disabled" variant="primary">{{ __('Confirm Make Shared') }}</flux:button></div>
        </div>
    </flux:modal>

</div>
