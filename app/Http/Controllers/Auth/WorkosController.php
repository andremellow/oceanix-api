<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticatePlatformAccount;
use App\Actions\Auth\AuthenticateSocialLogin;
use App\Exceptions\SocialLoginProviderException;
use App\Http\Controllers\Controller;
use App\Services\SocialLogin\OauthStateSigner;
use App\Services\SocialLogin\SocialLoginManager;
use App\Tenancy\TenantContext;
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
        private readonly AuthenticatePlatformAccount $authenticatePlatform,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        return $this->startRedirect($request, 'tenant');
    }

    public function platformRedirect(Request $request): RedirectResponse
    {
        // Platform identity is global. Never leak a previously selected tenant into the
        // WorkOS authorization URL for this flow.
        app(TenantContext::class)->clear();

        return $this->startRedirect($request, 'platform');
    }

    private function startRedirect(Request $request, string $mode): RedirectResponse
    {
        // Bind the OAuth state to this browser session so a state minted elsewhere cannot
        // complete a login into someone else's account (login CSRF).
        $nonce = Str::random(40);
        $request->session()->put('workos_oauth_state', $nonce);
        $request->session()->put('workos_login_mode', $mode);

        try {
            $url = $this->socialLogin->provider('workos')->redirectUrl(
                $this->stateSigner->issue($nonce),
                route('auth.workos.callback'),
            );
        } catch (SocialLoginProviderException $e) {
            return $this->failedLoginRedirect($mode)
                ->withErrors(['workos' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedNonce = $request->session()->pull('workos_oauth_state');
        $mode = $request->session()->pull('workos_login_mode', 'tenant');

        try {
            if (! is_string($expectedNonce) || $expectedNonce === '') {
                throw SocialLoginProviderException::invalidState();
            }

            $this->stateSigner->verify($request->string('state')->toString(), $expectedNonce);

            $identity = $this->socialLogin->provider('workos')->userFromCallback(
                $request,
                route('auth.workos.callback'),
            );

            if ($mode === 'platform') {
                $account = $this->authenticatePlatform->handle($identity);
            } else {
                // Account resolution can also reject the sign-in (unprovisioned or inactive
                // account, unverified email), so keep it inside the catch.
                $user = $this->authenticate->handle($identity);
            }
        } catch (SocialLoginProviderException $e) {
            return $this->failedLoginRedirect($mode)
                ->withErrors(['workos' => $e->getMessage()]);
        }

        if ($mode === 'platform') {
            $request->session()->put('platform_account_id', $account->id);
            $request->session()->regenerate();

            return redirect()->intended(route('platform.dashboard'));
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function failedLoginRedirect(string $mode = 'tenant'): RedirectResponse
    {
        if ($mode === 'platform') {
            return redirect()->route('platform.login');
        }

        $company = app(TenantContext::class)->get();

        return $company === null
            ? redirect()->route('login')
            : redirect()->route('tenant.login', $company);
    }
}
