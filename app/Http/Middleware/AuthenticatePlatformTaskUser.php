<?php

namespace App\Http\Middleware;

use App\Models\PlatformTaskUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Oceanix\Platform\Services\Platform\PlatformAccess;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePlatformTaskUser
{
    public function __construct(private readonly PlatformAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $account = $this->access->authorize();
        $user = PlatformTaskUser::query()
            ->where('account_id', $account->getKey())
            ->firstOrFail();

        Auth::setUser($user);

        return $next($request);
    }
}
