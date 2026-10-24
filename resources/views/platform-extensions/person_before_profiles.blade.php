<div class="grid gap-5 lg:grid-cols-3">
        <section class="detail-card">
            <span class="detail-card-icon"><flux:icon.building-office-2 class="size-5" /></span>
            <h2 class="detail-card-title">{{ __('Departments') }}</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @forelse ($user->departments as $department)
                    <span class="status-pill status-pill--accent">{{ $department->name }}</span>
                @empty
                    <span class="text-sm text-[#8a9298]">{{ __('Not provided') }}</span>
                @endforelse
            </div>
        </section>
        <section class="detail-card">
            <span class="detail-card-icon"><flux:icon.identification class="size-5" /></span>
            <h2 class="detail-card-title">{{ __('Job functions') }}</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @forelse ($user->jobFunctions as $jobFunction)
                    <span class="status-pill status-pill--accent">{{ $jobFunction->name }}</span>
                @empty
                    <span class="text-sm text-[#8a9298]">{{ __('Not provided') }}</span>
                @endforelse
            </div>
        </section>
        <section class="detail-card">
            <span class="detail-card-icon"><flux:icon.calendar class="size-5" /></span>
            <h2 class="detail-card-title">{{ __('Employment') }}</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-[#8a9298]">{{ __('Hired') }}</dt>
                    <dd class="font-semibold text-[#262d33]">{{ $user->hired_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-[#8a9298]">{{ __('Terminated') }}</dt>
                    <dd class="font-semibold text-[#262d33]">{{ $user->terminated_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-[#8a9298]">{{ __('Access profiles') }}</dt>
                    <dd class="font-semibold text-[#262d33]">{{ $user->roles->pluck('name')->join(', ') ?: '—' }}</dd>
                </div>
            </dl>
        </section>
    </div>
