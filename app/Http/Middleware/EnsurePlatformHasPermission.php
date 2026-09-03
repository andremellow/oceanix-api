<?php

namespace App\Http\Middleware;

use App\Enums\PlatformPermission;
use App\Services\Platform\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformHasPermission
{
    public function __construct(private readonly PlatformAccess $access) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $this->access->authorizePermission(PlatformPermission::from($permission));

        return $next($request);
    }
}
