<?php

namespace App\Services\SocialLogin;

use App\Contracts\SocialIdentityProvider;
use InvalidArgumentException;

class SocialLoginManager
{
    /**
     * @param  array<string, SocialIdentityProvider>  $providers
     */
    public function __construct(private readonly array $providers) {}

    /**
     * @return array<string, string>
     */
    public function enabledProviders(): array
    {
        return collect(config('services.social_login.providers', []))
            ->filter(fn (array $provider): bool => (bool) ($provider['enabled'] ?? false))
            ->mapWithKeys(fn (array $provider, string $key): array => [
                $key => (string) ($provider['label'] ?? ucfirst($key)),
            ])
            ->all();
    }

    public function provider(string $provider): SocialIdentityProvider
    {
        $provider = strtolower($provider);

        if (! array_key_exists($provider, $this->enabledProviders()) || ! isset($this->providers[$provider])) {
            throw new InvalidArgumentException("Unsupported social login provider [{$provider}].");
        }

        return $this->providers[$provider];
    }
}
