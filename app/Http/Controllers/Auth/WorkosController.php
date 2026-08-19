<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticateSocialLogin;
use App\Exceptions\SocialLoginProviderException;
use App\Http\Controllers\Controller;
use App\Services\SocialLogin\OauthStateSigner;
use App\Services\SocialLogin\SocialLoginManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class WorkosController extends Controller
{
    public function __construct(
        private readonly SocialLoginManager $socialLogin,
        private readonly OauthStateSigner $stateSigner,
        private readonly AuthenticateSocialLogin $authenticate,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        // Bind the OAuth state to this browser session so a state minted elsewhere cannot
        // complete a login into someone else's account (login CSRF).
        $nonce = Str::random(40);
        $request->session()->put('workos_oauth_state', $nonce);

        try {
            $url = $this->socialLogin->provider('workos')->redirectUrl(
                $this->stateSigner->issue($nonce),
                route('auth.workos.callback'),
            );
        } catch (SocialLoginProviderException $e) {
            return redirect()->route('login')->withErrors(['workos' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedNonce = $request->session()->pull('workos_oauth_state');

        try {
            if (! is_string($expectedNonce) || $expectedNonce === '') {
                throw SocialLoginProviderException::invalidState();
            }

            $this->stateSigner->verify($request->string('state')->toString(), $expectedNonce);

            $identity = $this->socialLogin->provider('workos')->userFromCallback(
                $request,
                route('auth.workos.callback'),
            );

            // Account resolution can also reject the sign-in (unprovisioned or inactive
            // account, unverified email), so keep it inside the catch.
            $user = $this->authenticate->handle($identity);
        } catch (SocialLoginProviderException $e) {
            return redirect()->route('login')->withErrors(['workos' => $e->getMessage()]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
