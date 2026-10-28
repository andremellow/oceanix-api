<div class="space-y-7">
    <x-page-hero :kicker="__('Platform administration')" :title="__('Platform administrators')" :description="__('Accounts that can create and manage companies across the platform.')" />
    <x-status-message />

    <section class="detail-card space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="detail-card-title">{{ __('Super administrators') }}</h2>
                <p class="mt-1 text-sm text-[#5f6a71]">{{ __('These accounts administer the platform itself, not a specific company.') }}</p>
            </div>
            <span class="status-pill status-pill--neutral">{{ trans_choice('{0} No platform administrators|{1} :count platform administrator|[2,*] :count platform administrators', $administrators->count(), ['count' => $administrators->count()]) }}</span>
        </div>

        <div class="overflow-x-auto rounded-[16px] border border-[#dde3e7]">
            <table class="min-w-full divide-y divide-[#e5eaed] text-left text-sm">
                <thead class="bg-[#f8fafb] text-[10px] font-bold uppercase tracking-[.12em] text-[#7a858b]"><tr><th class="px-4 py-3">{{ __('Name') }}</th><th class="px-4 py-3">{{ __('Email') }}</th><th class="px-4 py-3">{{ __('Status') }}</th></tr></thead>
                <tbody class="divide-y divide-[#edf0f2] bg-white">
                    @foreach ($administrators as $administrator)
                        <tr wire:key="platform-administrator-{{ $administrator->id }}">
                            <td class="px-4 py-3 font-semibold text-[#262d33]">{{ $administrator->name }}</td>
                            <td class="px-4 py-3 text-[#5f6a71]">{{ $administrator->email }}</td>
                            <td class="px-4 py-3"><span class="status-pill {{ $administrator->status === 'active' ? 'status-pill--positive' : 'status-pill--neutral' }}">{{ $administrator->status === 'pending_verification' ? __('Awaiting email verification') : __(ucfirst($administrator->status)) }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form wire:submit="inviteAdministrator" class="grid gap-4 border-t border-[#e5eaed] pt-4 sm:grid-cols-2">
            <p class="text-sm text-secondary sm:col-span-2">{{ __('Verified existing accounts receive platform access. Unverified existing accounts await email verification. Email addresses without an existing account receive an invitation.') }}</p>
            <flux:input wire:model="administratorName" :label="__('Name')" required />
            <flux:input wire:model="administratorEmail" type="email" :label="__('Email')" required />
            @error('administratorRegistration') <p role="alert" class="text-red-700 sm:col-span-2">{{ $message }}</p> @enderror
            <p wire:loading wire:target="inviteAdministrator" role="status" class="text-sm text-secondary sm:col-span-2">{{ __('Checking account and adding administrator…') }}</p>
            <div class="sm:col-span-2"><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="inviteAdministrator">{{ __('Add administrator') }}</flux:button></div>
        </form>
    </section>
</div>
