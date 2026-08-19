<?php

namespace App\Data;

/** Normalized identity returned by any SocialIdentityProvider implementation. */
class SocialIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $providerId,
        public readonly string $email,
        public readonly ?string $name = null,
        public readonly ?string $avatarUrl = null,
        public readonly bool $emailVerified = false,
    ) {}
}
