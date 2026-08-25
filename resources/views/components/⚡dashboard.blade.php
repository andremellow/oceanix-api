<?php

use App\Enums\Permission;
use App\Services\Compliance\ComplianceOverview;
use App\Services\Compliance\EmployeeTrainingBoard;
use Livewire\Component;

new class extends Component
{
    /** @var array<string, int> */
    public array $metrics = [];

    public int $openCount = 0;

    public int $overdueCount = 0;

    public bool $showsCompliance = false;

    public function mount(ComplianceOverview $overview, EmployeeTrainingBoard $board): void
    {
        $this->showsCompliance = auth()->user()->can(Permission::ComplianceDashboardView->value);

        if ($this->showsCompliance) {
            $this->metrics = $overview->metrics(auth()->user());
        }

        $personal = $board->build(auth()->user());
        $this->overdueCount = $personal['overdue']->count();
        $this->openCount = $personal['overdue']->count()
            + $personal['due_soon']->count()
            + $personal['in_progress']->count()
            + $personal['upcoming']->count();
    }

    /** The operational table is loaded lazily so the hero paints immediately. */
    public function with(ComplianceOverview $overview): array
    {
        return [
            'attention' => $this->showsCompliance
                ? $overview->assignments(['due_bucket' => 'overdue_60_plus'], auth()->user())->limit(8)->get()
                    ->concat($overview->assignments(['status' => 'open'], auth()->user())->limit(8)->get())
                    ->unique('id')
                    ->take(8)
                : collect(),
        ];
    }
};
?>

<div class="space-y-8">
    <section class="relative overflow-hidden rounded-[28px] bg-[#16222a] px-6 py-8 text-white shadow-[0_20px_50px_-28px_rgba(14,28,36,.6)] sm:px-9 sm:py-10">
        <div class="absolute -right-14 -top-24 size-64 rounded-full bg-[#69c8e2]/15 blur-2xl"></div>
        <div class="absolute -bottom-24 right-40 size-52 rounded-full bg-[#f1c653]/10 blur-3xl"></div>
        <div class="relative max-w-2xl">
            <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[.07] px-3 py-1.5 text-xs font-semibold text-[#cfe3ea]">
                <span class="size-1.5 rounded-full bg-[#69c8e2]"></span>
                {{ $showsCompliance ? __('ui.operation_overview') : __('ui.your_training') }}
            </span>
            <h1 class="mt-5 text-3xl font-bold tracking-[-.035em] sm:text-4xl">{{ __('ui.greeting', ['name' => str(auth()->user()->name)->before(' ')]) }}</h1>
            <p class="mt-3 max-w-xl text-sm leading-6 text-[#b3bec4] sm:text-base">
                {{ $showsCompliance ? __('ui.admin_summary') : __('ui.employee_summary') }}
            </p>
        </div>
        <div class="relative mt-8 flex flex-wrap gap-3">
            <flux:button :href="route('my-training')" wire:navigate variant="primary" class="!bg-[#69c8e2] !text-[#0f2530] hover:!bg-[#84d6ec]">
                {{ $openCount > 0 ? __('ui.continue_training') : __('ui.my_training') }}
            </flux:button>
            @can(\App\Enums\Permission::AssignmentsView->value)
                <flux:button :href="route('assignments.index')" wire:navigate variant="ghost" class="!border-white/10 !bg-white/[.07] !text-white hover:!bg-white/[.12]">{{ __('ui.open_assignments') }}</flux:button>
            @endcan
        </div>
    </section>

    @if ($overdueCount > 0)
        <a href="{{ route('my-training') }}" wire:navigate class="flex items-center gap-4 rounded-[22px] border border-[#f0cfcf] bg-[#fdf3f3] p-5 transition hover:-translate-y-0.5 hover:border-[#e3b8b8]">
            <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-[#f8e2e2] text-[#b23a3a]"><flux:icon.exclamation-triangle class="size-5" /></span>
            <span class="min-w-0">
                <span class="block text-base font-bold text-[#8f2f2f]">{{ trans_choice('ui.personal_overdue', $overdueCount, ['count' => $overdueCount]) }}</span>
                <span class="mt-0.5 block text-sm text-[#8a6a6a]">{{ __('ui.personal_overdue_help') }}</span>
            </span>
            <span class="ml-auto hidden text-sm font-bold text-[#b23a3a] sm:block">{{ __('ui.resolve_now') }} <span aria-hidden="true">→</span></span>
        </a>
    @endif

    @if ($showsCompliance)
        <section>
            <div class="mb-4 flex items-end justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[.14em] text-[#8a9298]">{{ __('ui.compliance') }}</p>
                    <h2 class="mt-1 text-xl font-bold tracking-tight text-[#242a2f]">{{ __('ui.workforce_status') }}</h2>
                </div>
                <span class="hidden text-sm text-[#879096] sm:block">{{ __('ui.derived_from_assignments') }}</span>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @can(\App\Enums\Permission::AssignmentsView->value)
                    <a
                        href="{{ route('assignments.index', ['company' => app(App\Tenancy\TenantContext::class)->get(), 'status' => 'open']) }}"
                        wire:navigate
                        class="metric-card metric-card--teal transition hover:-translate-y-0.5 hover:border-[#acd9e4] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1c6b84]"
                        aria-label="{{ __('View all open training for people in my scope') }}">
                @else
                    <div class="metric-card metric-card--teal">
                @endcan
                    <span class="metric-icon"><flux:icon.users class="size-5" /></span>
                    <p class="metric-label">{{ __('ui.active_people') }}</p>
                    <p class="metric-value">{{ $metrics['people'] }}</p>
                    <p class="metric-detail">{{ __('ui.compliant_count', ['count' => $metrics['compliant']]) }}</p>
                @can(\App\Enums\Permission::AssignmentsView->value)
                    </a>
                @else
                    </div>
                @endcan
                <div class="metric-card metric-card--amber">
                    <span class="metric-icon"><flux:icon.clock class="size-5" /></span>
                    <p class="metric-label">{{ __('ui.due_soon') }}</p>
                    <p class="metric-value">{{ $metrics['due_soon'] }}</p>
                    <p class="metric-detail">{{ __('ui.next_days', ['days' => \App\Services\Compliance\ComplianceOverview::DUE_SOON_DAYS]) }}</p>
                </div>
                <div class="metric-card metric-card--rose">
                    <span class="metric-icon"><flux:icon.exclamation-triangle class="size-5" /></span>
                    <p class="metric-label">{{ __('ui.overdue') }}</p>
                    <p class="metric-value">{{ $metrics['overdue'] }}</p>
                    <p class="metric-detail">{{ __('ui.critical_count', ['count' => $metrics['critical_overdue']]) }}</p>
                </div>
                <div class="metric-card metric-card--violet">
                    <span class="metric-icon"><flux:icon.chart-bar class="size-5" /></span>
                    <p class="metric-label">{{ __('ui.completion_rate') }}</p>
                    <p class="metric-value">{{ $metrics['completion_rate'] }}%</p>
                    <p class="metric-detail">{{ __('ui.in_progress_count', ['count' => $metrics['in_progress']]) }}</p>
                </div>
            </div>
        </section>

        <section class="rounded-[24px] border border-[#dde3e7] bg-white shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]">
            <div class="flex flex-col justify-between gap-3 p-5 sm:flex-row sm:items-center sm:p-6">
                <div>
                    <h2 class="text-base font-bold text-[#262d33]">{{ __('ui.needs_attention') }}</h2>
                    <p class="mt-0.5 text-sm text-[#6f797f]">{{ __('ui.needs_attention_help') }}</p>
                </div>
                @can(\App\Enums\Permission::AssignmentsView->value)
                    <flux:button :href="route('assignments.index')" wire:navigate variant="ghost" size="sm">{{ __('ui.view_all') }}</flux:button>
                @endcan
            </div>

            @if ($attention->isEmpty())
                <div class="mx-5 mb-6 rounded-[20px] border border-dashed border-[#d7dee3] p-8 text-center sm:mx-6">
                    <span class="mx-auto grid size-11 place-items-center rounded-2xl bg-[#eef3f6] text-[#7d878e]"><flux:icon.check-circle class="size-5" /></span>
                    <p class="mt-4 text-base font-bold text-[#262d33]">{{ __('ui.nothing_overdue') }}</p>
                    <p class="mx-auto mt-1 max-w-sm text-sm text-[#6f797f]">{{ __('ui.nothing_overdue_help') }}</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-b-[24px] border-t border-[#eaeef1]">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr>
                                <th>{{ __('Employee') }}</th>
                                <th>{{ __('Department') }}</th>
                                <th>{{ __('Course') }}</th>
                                <th>{{ __('Due date') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th class="text-right">{{ __('Days overdue') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($attention as $assignment)
                                <tr class="border-t">
                                    <td>
                                        <span class="block font-semibold text-[#262d33]">{{ $assignment->user->name }}</span>
                                        <span class="block text-xs text-[#8a9298]">{{ $assignment->user->email }}</span>
                                    </td>
                                    <td class="text-[#5f6a71]">{{ $assignment->user->departments->pluck('name')->join(', ') ?: '—' }}</td>
                                    <td class="text-[#5f6a71]">{{ $assignment->course->title }}</td>
                                    <td class="text-[#5f6a71]">{{ $assignment->due_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</td>
                                    <td><span class="status-pill {{ $assignment->status->pillModifier() }}">{{ $assignment->status->label() }}</span></td>
                                    <td class="text-right font-bold {{ $assignment->daysOverdue() > 0 ? 'text-[#b23a3a]' : 'text-[#8a9298]' }}">
                                        {{ $assignment->daysOverdue() > 0 ? $assignment->daysOverdue() : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section>
            <div class="mb-4 flex items-end justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[.14em] text-[#8a9298]">{{ __('Shortcuts') }}</p>
                    <h2 class="mt-1 text-xl font-bold tracking-tight text-[#242a2f]">{{ __('ui.program_management') }}</h2>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @can(\App\Enums\Permission::CoursesView->value)
                    <a href="{{ route('courses.index') }}" wire:navigate class="saas-feature-card group">
                        <span class="saas-feature-icon bg-[#e4f0f5] text-[#1c6b84]"><flux:icon.book-open class="size-5" /></span>
                        <span class="mt-5 block text-base font-bold">{{ __('Courses') }}</span>
                        <span class="mt-1.5 block text-sm leading-5 text-[#778188]">{{ __('ui.courses_help') }}</span>
                        <span class="mt-5 inline-flex items-center gap-1 text-xs font-bold text-[#1c6b84]">{{ __('ui.manage') }} <span aria-hidden="true">→</span></span>
                    </a>
                @endcan
                @can(\App\Enums\Permission::RequirementsView->value)
                    <a href="{{ route('requirements.index') }}" wire:navigate class="saas-feature-card group">
                        <span class="saas-feature-icon bg-[#fff3d8] text-[#b68022]"><flux:icon.clipboard-document-check class="size-5" /></span>
                        <span class="mt-5 block text-base font-bold">{{ __('Requirements') }}</span>
                        <span class="mt-1.5 block text-sm leading-5 text-[#778188]">{{ __('ui.requirements_help') }}</span>
                        <span class="mt-5 inline-flex items-center gap-1 text-xs font-bold text-[#9e741f]">{{ __('ui.configure') }} <span aria-hidden="true">→</span></span>
                    </a>
                @endcan
                @can(\App\Enums\Permission::PeopleView->value)
                    <a href="{{ route('people.index') }}" wire:navigate class="saas-feature-card group">
                        <span class="saas-feature-icon bg-[#eee8f6] text-[#8466a0]"><flux:icon.user-group class="size-5" /></span>
                        <span class="mt-5 block text-base font-bold">{{ __('People') }}</span>
                        <span class="mt-1.5 block text-sm leading-5 text-[#778188]">{{ __('ui.people_help') }}</span>
                        <span class="mt-5 inline-flex items-center gap-1 text-xs font-bold text-[#795b95]">{{ __('ui.view_directory') }} <span aria-hidden="true">→</span></span>
                    </a>
                @endcan
                @can(\App\Enums\Permission::CertificatesView->value)
                    <a href="{{ route('certificates.index') }}" wire:navigate class="saas-feature-card group">
                        <span class="saas-feature-icon bg-[#e8edf4] text-[#607994]"><flux:icon.document-check class="size-5" /></span>
                        <span class="mt-5 block text-base font-bold">{{ __('Certificates') }}</span>
                        <span class="mt-1.5 block text-sm leading-5 text-[#778188]">{{ __('ui.certificates_help') }}</span>
                        <span class="mt-5 inline-flex items-center gap-1 text-xs font-bold text-[#58718b]">{{ __('ui.administer') }} <span aria-hidden="true">→</span></span>
                    </a>
                @endcan
            </div>
        </section>
    @endif
</div>
