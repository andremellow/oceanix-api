<?php

namespace App\Http\Middleware;

use App\Services\Platform\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsPlatformAdmin
{
    public function __construct(private readonly PlatformAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->access->account() === null) {
            if (! Auth::check()) {
                return redirect()->guest(route('platform.login'));
            }

            abort(403);
        }

        return $next($request);
    }
}
