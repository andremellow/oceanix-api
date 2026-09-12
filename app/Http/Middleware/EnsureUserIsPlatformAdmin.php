<?php

namespace App\Http\Middleware;

use App\Services\Platform\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsPlatformAdmin
{
    public function __construct(private readonly PlatformAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->access->account() === null) {
            // Livewire action requests need a terminal forbidden response. Redirecting a
            // revoked platform session to the login page leaves the editor request pending
            // instead of allowing its transport hook to retain local work and lock writes.
            if (Livewire::isLivewireRequest() && $request->session()->has('platform_account_id')) {
                abort(403);
            }

            if (! Auth::check()) {
                return redirect()->guest(route('platform.login'));
            }

            abort(403);
        }

        return $next($request);
    }
}
