<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Operational shell guard. Employees without granted permissions never reach it. */
class EnsureUserCanAccessControlCenter
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->canAccessControlCenter()) {
            abort(403);
        }

        return $next($request);
    }
}
