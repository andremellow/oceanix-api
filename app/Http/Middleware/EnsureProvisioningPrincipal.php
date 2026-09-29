<?php

namespace App\Http\Middleware;

use App\Models\ServicePrincipal;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureProvisioningPrincipal
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $token = $bearer ? PersonalAccessToken::findToken($bearer) : null;
        $expiration = config('sanctum.expiration');
        if (! $token || ($token->expires_at && $token->expires_at->isPast()) || ($expiration && $token->created_at->lte(now()->subMinutes($expiration)))) {
            return response()->json(['message' => 'Invalid provisioning authentication.'], 401);
        }
        $principal = $token->tokenable;
        if (! $principal instanceof ServicePrincipal || ! $principal->active || $principal->product !== 'compliance' || $principal->environment !== app()->environment() || ! in_array('companies:provision', $token->abilities ?? [], true)) {
            return response()->json(['message' => 'Provisioning access denied.'], 403);
        }
        $request->attributes->set('provisioning_principal', $principal);

        return $next($request);
    }
}
