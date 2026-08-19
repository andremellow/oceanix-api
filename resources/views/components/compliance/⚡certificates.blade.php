<?php

use App\Actions\Certificates\RevokeCertificate;
use App\Models\Certificate;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $revokingId = null;

    public string $revocationReason = '';

    public function startRevoking(int $certificateId): void
    {
        $this->authorize('revoke', Certificate::query()->findOrFail($certificateId));

        $this->revokingId = $certificateId;
        $this->revocationReason = '';
    }

    public function revoke(RevokeCertificate $action): void
    {
        $certificate = Certificate::query()->findOrFail($this->revokingId);

        $this->authorize('revoke', $certificate);

        $validated = $this->validate([
            'revocationReason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->handle($certificate, $validated['revocationReason']);

        $this->revokingId = null;

        session()->flash('status', __('ui.certificate_revoked'));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $query = Certificate::query()->with(['user', 'course', 'courseVersion']);

        if ($this->search !== '') {
            $term = '%'.strtolower($this->search).'%';
            $query->where(fn ($scoped) => $scoped
                ->whereRaw('lower(certificate_number) like ?', [$term])
                ->orWhereHas('user', fn ($user) => $user->whereRaw('lower(name) like ?', [$term])));
        }

        return ['certificates' => $query->latest('issued_at')->paginate(25)];
    }
};
?>

<div class="admin-page space-y-7">
    <x-page-hero
        :kicker="__('ui.compliance')"
        :title="__('Certificates')"
        :description="__('ui.certificates_page_description')">
        <span class="status-pill status-pill--accent">{{ trans_choice('ui.results_count', $certificates->total(), ['count' => $certificates->total()]) }}</span>
    </x-page-hero>

    @if (session('status'))
        <flux:callout variant="success" :heading="session('status')" />
    @endif

    <div class="form-panel rounded-[20px] border border-[#dde3e7] p-4 sm:p-5">
        <flux:input wire:model.live.debounce.400ms="search" class="admin-control" icon="magnifying-glass" :label="__('Search')" :placeholder="__('Certificate number or holder')" />
    </div>

    @if ($certificates->isEmpty())
        <x-empty-state
            icon="document-check"
            :title="__('ui.no_certificates')"
            :description="__('ui.no_certificates_help')" />
    @else
        <div class="overflow-x-auto rounded-[20px] border border-[#dde3e7] shadow-[0_12px_35px_-30px_rgba(20,28,34,.42)]">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>{{ __('Certificate') }}</th>
                        <th>{{ __('Holder') }}</th>
                        <th>{{ __('Course') }}</th>
                        <th>{{ __('Issued') }}</th>
                        <th>{{ __('Valid until') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($certificates as $certificate)
                        <tr class="border-t">
                            <td class="font-semibold text-[#262d33]">{{ $certificate->certificate_number }}</td>
                            <td class="text-[#5f6a71]">{{ $certificate->user->name }}</td>
                            <td class="text-[#5f6a71]">
                                {{ $certificate->course->title }}
                                <span class="block text-xs text-[#8a9298]">{{ __('Version :number', ['number' => $certificate->courseVersion->version_number]) }}</span>
                            </td>
                            <td class="text-[#5f6a71]">{{ $certificate->issued_at->locale(app()->getLocale())->translatedFormat('M j, Y') }}</td>
                            <td class="text-[#5f6a71]">{{ $certificate->expires_at?->locale(app()->getLocale())->translatedFormat('M j, Y') ?? __('No expiry') }}</td>
                            <td>
                                @if ($certificate->isRevoked())
                                    <span class="status-pill status-pill--negative">{{ __('Revoked') }}</span>
                                @elseif ($certificate->isExpired())
                                    <span class="status-pill status-pill--warning">{{ __('Expired') }}</span>
                                @else
                                    <span class="status-pill status-pill--positive">{{ __('Valid') }}</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <flux:button :href="route('certificates.download', $certificate)" variant="ghost" size="sm" icon="arrow-down-tray">{{ __('PDF') }}</flux:button>
                                    @can('revoke', $certificate)
                                        <flux:button wire:click="startRevoking({{ $certificate->id }})" variant="ghost" size="sm">{{ __('Revoke') }}</flux:button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>{{ $certificates->links() }}</div>
    @endif

    <flux:modal :open="$revokingId !== null" wire:model.self="revokingId" class="max-w-lg">
        <form wire:submit="revoke" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Revoke certificate') }}</flux:heading>
                <flux:text class="mt-2">{{ __('ui.revoke_help') }}</flux:text>
            </div>

            <flux:textarea wire:model="revocationReason" class="admin-control" :label="__('Reason')" rows="3" />

            <div class="flex justify-end gap-2">
                <flux:button x-on:click="$wire.revokingId = null" variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="danger">{{ __('Revoke certificate') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
