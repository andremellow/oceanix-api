<?php

namespace App\Contracts;

use App\Data\SocialIdentity;
use Illuminate\Http\Request;

interface SocialIdentityProvider
{
    public function redirectUrl(string $state, string $callbackUrl, ?string $codeChallenge = null): string;

    public function userFromCallback(Request $request, string $callbackUrl): SocialIdentity;
}
