<?php

namespace App\Services\SocialLogin;

use App\Contracts\SocialIdentityProvider;
use App\Data\SocialIdentity;
use App\Exceptions\SocialLoginProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WorkosAuthKitIdentityProvider implements SocialIdentityProvider
{
    public function redirectUrl(string $state, string $callbackUrl, ?string $codeChallenge = null): string
    {
        $query = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $callbackUrl,
            'response_type' => 'code',
            'provider' => 'authkit',
            // The browser can keep an AuthKit session after the local session is revoked.
            // Always require an explicit login so a shared device cannot silently switch user.
            'prompt' => 'login',
            'state' => $state,
        ];

        if ($codeChallenge !== null && $codeChallenge !== '') {
            $query['code_challenge'] = $codeChallenge;
            $query['code_challenge_method'] = 'S256';
        }

        return 'https://api.workos.com/user_management/authorize?'.http_build_query(
            $query, '', '&', PHP_QUERY_RFC3986
        );
    }

    public function userFromCallback(Request $request, string $callbackUrl): SocialIdentity
    {
        $code = $request->string('code')->toString();

        if ($code === '') {
            throw SocialLoginProviderException::unavailable();
        }

        $payload = [
            'client_id' => $this->clientId(),
            'client_secret' => $this->apiKey(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $callbackUrl,
        ];

        $codeVerifier = $request->string('code_verifier')->toString();

        if ($codeVerifier !== '') {
            $payload['code_verifier'] = $codeVerifier;
        }

        try {
            $response = Http::asJson()->post('https://api.workos.com/user_management/authenticate', $payload);

            if ($response->failed()) {
                Log::warning('WorkOS authenticate request failed', [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                    'redirect_uri' => $callbackUrl,
                ]);

                throw SocialLoginProviderException::unavailable();
            }
        } catch (ConnectionException) {
            throw SocialLoginProviderException::unavailable();
        }

        $providerId = (string) $response->json('user.id');
        $email = (string) $response->json('user.email');

        if ($providerId === '' || $email === '') {
            throw SocialLoginProviderException::unavailable();
        }

        $firstName = $response->json('user.first_name');
        $lastName = $response->json('user.last_name');
        $avatarUrl = $response->json('user.profile_picture_url');
        $name = trim(implode(' ', array_filter([
            is_string($firstName) ? $firstName : null,
            is_string($lastName) ? $lastName : null,
        ])));

        return new SocialIdentity(
            provider: 'workos',
            providerId: $providerId,
            email: $email,
            name: $name !== '' ? $name : null,
            avatarUrl: is_string($avatarUrl) && $avatarUrl !== '' ? $avatarUrl : null,
            emailVerified: filter_var($response->json('user.email_verified'), FILTER_VALIDATE_BOOLEAN),
        );
    }

    private function clientId(): string
    {
        $clientId = (string) config('services.workos.client_id');

        if ($clientId === '') {
            throw SocialLoginProviderException::unavailable();
        }

        return $clientId;
    }

    private function apiKey(): string
    {
        $apiKey = (string) config('services.workos.api_key');

        if ($apiKey === '') {
            throw SocialLoginProviderException::unavailable();
        }

        return $apiKey;
    }
}
