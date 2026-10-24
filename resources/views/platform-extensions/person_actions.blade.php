@if (app()->environment(['local', 'testing']) && auth()->user()->isAdmin() && auth()->id() !== $user->id)
            <form method="POST" action="{{ route('impersonation.start', ['company' => app(App\Tenancy\TenantContext::class)->get(), 'user' => $user]) }}">
                @csrf
                <flux:button type="submit" variant="ghost" size="sm" icon="user-circle">{{ __('Impersonate user') }}</flux:button>
            </form>
        @endif
