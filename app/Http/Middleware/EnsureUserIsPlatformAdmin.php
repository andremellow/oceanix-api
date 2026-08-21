<?php

namespace App\Http\Middleware;

use App\Services\Platform\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsPlatformAdmin
{
    public function __construct(private readonly PlatformAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->access->authorize();

        return $next($request);
    }
}
