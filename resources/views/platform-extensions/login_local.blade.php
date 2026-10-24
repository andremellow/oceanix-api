        @if ($company && Route::has('auth.local'))
            {{-- Visible only in local development, while WorkOS is not configured yet. --}}
            <div class="mt-7 border-t border-[#e5eaed] pt-5 text-center">
                <p class="text-[10px] font-bold uppercase tracking-[.14em] text-[#9aa3a9]">{{ __('ui.local_development') }}</p>
                <flux:button href="{{ route('auth.local') }}" size="sm" class="mt-3 w-full !border !border-[#ccd4d9] !bg-white !font-semibold !text-[#323a40] hover:!bg-[#f2f6f8]">
                    {{ __('ui.local_sign_in', ['email' => config('oceanix.local_auth_email')]) }}
                </flux:button>
            </div>
        @endif
