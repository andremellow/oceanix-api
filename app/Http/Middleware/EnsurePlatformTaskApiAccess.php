<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\PlatformTaskUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformTaskApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredHash = config('tasks.platform_api.key_hash');
        $providedKey = $request->header('X-Tasks-Key');

        if (! is_string($configuredHash) || ! preg_match('/\A[a-f0-9]{64}\z/i', $configuredHash)
            || ! is_string($providedKey) || $providedKey === ''
            || ! hash_equals(strtolower($configuredHash), hash('sha256', $providedKey))) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $actorEmail = config('tasks.platform_api.actor_email');
        $actor = is_string($actorEmail) && $actorEmail !== ''
            ? PlatformTaskUser::query()->where('email', strtolower(trim($actorEmail)))->first()
            : null;
        $account = $actor?->account;

        abort_unless(
            $actor !== null
                && $actor->status === UserStatus::Active
                && $account?->is_platform_admin === true
                && $account->status === 'active',
            403,
        );

        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);

        return $next($request);
    }
}
