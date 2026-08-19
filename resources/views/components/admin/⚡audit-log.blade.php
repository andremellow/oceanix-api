<?php

use App\Models\AuditLog;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $action = '';

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $query = AuditLog::query()->with('actor');

        if ($this->action !== '') {
            $query->where('action', $this->action);
        }

        return [
            'entries' => $query->latest('id')->paginate(30),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.administration')"
        :title="__('Audit log')"
        :description="__('ui.audit_log_page_description')" />

    <div class="form-panel rounded-[20px] border border-[#dde3e7] p-4 sm:p-5">
        <flux:select wire:model.live="action" class="admin-control" :label="__('Action')">
            <option value="">{{ __('All actions') }}</option>
            @foreach ($actions as $value)
                <option value="{{ $value }}">{{ $value }}</option>
            @endforeach
        </flux:select>
    </div>

    @if ($entries->isEmpty())
        <x-empty-state
            icon="shield-check"
            :title="__('ui.no_audit_entries')"
            :description="__('ui.no_audit_entries_help')" />
    @else
        <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7] shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>{{ __('When') }}</th>
                        <th>{{ __('Actor') }}</th>
                        <th>{{ __('Action') }}</th>
                        <th>{{ __('Object') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr class="border-t">
                            <td class="text-[#5f6a71]">{{ $entry->created_at?->locale(app()->getLocale())->translatedFormat('M j, Y · H:i') }}</td>
                            <td class="font-semibold text-[#262d33]">{{ $entry->actor?->name ?? __('System') }}</td>
                            <td class="text-[#5f6a71]"><code>{{ $entry->action }}</code></td>
                            <td class="text-[#5f6a71]">{{ class_basename((string) $entry->auditable_type) }} #{{ $entry->auditable_id ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>{{ $entries->links() }}</div>
    @endif
</div>
