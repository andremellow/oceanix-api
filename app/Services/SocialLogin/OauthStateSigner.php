<?php

namespace App\Services\SocialLogin;

use App\Exceptions\SocialLoginProviderException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class OauthStateSigner
{
    private const TTL_MINUTES = 10;

    /**
     * Issue a signed OAuth state. Pass a binding (a per-session nonce) to tie the state to
     * the browser session that started the flow — required to defend the stateful web flow
     * against login CSRF.
     */
    public function issue(?string $binding = null): string
    {
        return Crypt::encryptString((string) json_encode([
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
            'binding' => $binding,
        ]));
    }

    /**
     * Verify a signed OAuth state. When $expectedBinding is provided, the state must carry a
     * matching binding (constant-time compared), so a state minted for another session — or
     * with none — is rejected.
     */
    public function verify(string $state, ?string $expectedBinding = null): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw SocialLoginProviderException::invalidState();
        }

        if (! is_array($payload) || ! isset($payload['expires_at'])) {
            throw SocialLoginProviderException::invalidState();
        }

        if (Carbon::createFromTimestamp((int) $payload['expires_at'])->isPast()) {
            throw SocialLoginProviderException::invalidState();
        }

        if ($expectedBinding !== null) {
            $binding = $payload['binding'] ?? null;

            if (! is_string($binding) || ! hash_equals($binding, $expectedBinding)) {
                throw SocialLoginProviderException::invalidState();
            }
        }
    }
}
