<?php

use Illuminate\Support\Carbon;
use Oceanix\Platform\Auth\Exceptions\SocialLoginProviderException;
use Oceanix\Platform\Auth\OauthStateSigner;

it('rejects expired and tampered shared OAuth state', function (): void {
    $signer = app(OauthStateSigner::class);
    $state = $signer->issue('session');
    $signer->verify($state, 'session');

    expect(fn () => $signer->verify($state.'tampered', 'session'))
        ->toThrow(SocialLoginProviderException::class);

    $this->travel(11)->minutes();
    expect(fn () => $signer->verify($state, 'session'))
        ->toThrow(SocialLoginProviderException::class);
    Carbon::setTestNow();
});
