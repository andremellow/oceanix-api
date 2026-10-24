<section class="detail-card">
        <span class="detail-card-icon"><flux:icon.calendar-days class="size-5" /></span>
        <h2 class="detail-card-title">{{ __('Upcoming training schedule') }}</h2>
        <p class="mt-1 text-sm text-[#6f797f]">{{ __('Expected and already-created occurrences for the next 3 months.') }}</p>
        <div class="mt-5">
            @if ($upcomingSchedule->isEmpty())
                <x-empty-state icon="calendar-days" :title="__('No upcoming occurrences')" :description="__('This person has no scheduled training in the next 3 months.')" />
            @else
                <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7]">
                    <table class="w-full text-left text-sm">
                        <thead><tr><th>{{ __('Requirement') }}</th><th>{{ __('Course') }}</th><th>{{ __('Cycle') }}</th><th>{{ __('Available') }}</th><th>{{ __('Due date') }}</th><th>{{ __('Status') }}</th></tr></thead>
                        <tbody>
                            @foreach ($upcomingSchedule as $row)
                                <tr class="border-t" wire:key="person-schedule-{{ $row['requirement_id'] }}-{{ $row['cycle'] }}">
                                    <td class="font-semibold text-[#262d33]">{{ $row['requirement_name'] }}</td>
                                    <td>{{ $row['course_title'] }}</td>
                                    <td>{{ $row['cycle'] }}</td>
                                    <td>{{ $row['available_at']?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</td>
                                    <td>{{ $row['due_at']->locale(app()->getLocale())->translatedFormat('M j, Y') }}</td>
                                    <td><span class="status-pill {{ $row['materialized'] ? 'status-pill--accent' : 'status-pill--neutral' }}">{{ $row['status'] }}{{ $row['estimated'] ? ' · '.__('Estimated') : '' }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $upcomingSchedule->links() }}</div>
            @endif
        </div>
    </section>

    <section class="detail-card space-y-5">
        <div>
            <span class="detail-card-icon"><flux:icon.user-group class="size-5" /></span>
            <h2 class="detail-card-title">{{ __('Management responsibilities') }}</h2>
            <p class="mt-1 text-sm text-[#6f797f]">{{ __('Select the departments and job functions this person manages.') }}</p>
        </div>
        <form wire:submit="saveManagementScopes" class="grid gap-5 lg:grid-cols-2">
            <fieldset class="space-y-2"><legend class="mb-2 text-sm font-bold text-[#262d33]">{{ __('Departments') }}</legend>
                @forelse ($departments as $department)<flux:checkbox wire:model="managedDepartmentIds" value="{{ $department->id }}" :label="$department->name" />@empty<p class="text-sm text-[#8a9298]">{{ __('No active departments') }}</p>@endforelse
            </fieldset>
            <fieldset class="space-y-2"><legend class="mb-2 text-sm font-bold text-[#262d33]">{{ __('Job functions') }}</legend>
                @forelse ($jobFunctions as $jobFunction)<flux:checkbox wire:model="managedJobFunctionIds" value="{{ $jobFunction->id }}" :label="$jobFunction->name" />@empty<p class="text-sm text-[#8a9298]">{{ __('No active job functions') }}</p>@endforelse
            </fieldset>
            @can('manage', $user)<div class="lg:col-span-2"><flux:button type="submit" variant="primary">{{ __('Save management responsibilities') }}</flux:button></div>@endcan
        </form>
        @error('management') <flux:callout variant="danger" :heading="$message" /> @enderror
    </section>

    <section class="detail-card">
        <span class="detail-card-icon"><flux:icon.rectangle-stack class="size-5" /></span>
        <h2 class="detail-card-title">{{ __('Training record') }}</h2>
        <p class="mt-1 text-sm text-[#6f797f]">{{ __('ui.training_record_help') }}</p>

        <div class="mt-5">
            @if ($assignments->isEmpty())
                <x-empty-state
                    icon="rectangle-stack"
                    :title="__('ui.no_assignments')"
                    :description="__('ui.no_assignments_person_help')" />
            @else
                <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7]">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr>
                                <th>{{ __('Course') }}</th>
                                <th>{{ __('Origin') }}</th>
                                <th>{{ __('Due date') }}</th>
                                <th>{{ __('Completed') }}</th>
                                <th>{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($assignments as $assignment)
                                <tr class="border-t">
                                    <td>
                                        <span class="font-semibold text-[#262d33]">{{ $assignment->course->title }}</span>
                                        <span class="block text-xs text-[#8a9298]">{{ __('Version :number', ['number' => $assignment->courseVersion->version_number]) }}</span>
                                    </td>
                                    <td class="text-[#5f6a71]">{{ $assignment->origin_type->label() }}</td>
                                    <td class="text-[#5f6a71]">{{ $assignment->due_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</td>
                                    <td class="text-[#5f6a71]">{{ $assignment->completed_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? '—' }}</td>
                                    <td><span class="status-pill {{ $assignment->status->pillModifier() }}">{{ $assignment->status->label() }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>
