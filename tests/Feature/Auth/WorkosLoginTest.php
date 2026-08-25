<?php

use App\Models\Account;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\SocialLogin\OauthStateSigner;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->withSession(['company_id' => currentCompany()->id]);
});

it('shows the sign-in page to guests', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee(__('Company code'))
        ->assertDontSee(__('Platform administrator sign in'))
        ->assertDontSee(route('platform.login'), escape: false);

    $this->get(route('tenant.login', currentCompany()))
        ->assertOk()
        ->assertSee(__('ui.login_action'));

    $this->get(route('platform.login'))
        ->assertOk()
        ->assertSee(__('Sign in as platform administrator'))
        ->assertDontSee(__('Company code'));
});

it('redirects authenticated users away from sign-in', function (): void {
    $this->actingAs(employeeUser())
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));
});

it('sends the user to WorkOS with a session-bound state', function (): void {
    $response = $this->get(route('auth.workos.redirect'));

    $response->assertRedirectContains('api.workos.com/user_management/authorize');
    expect(session('workos_oauth_state'))->toBeString()->not->toBeEmpty();
});

it('scopes WorkOS sign-in to the selected company organization', function (): void {
    currentCompany()->update(['workos_organization_id' => 'org_company']);

    $this->get(route('auth.workos.redirect'))
        ->assertRedirectContains('organization_id=org_company');
});

it('never scopes platform administrator sign-in to a company organization', function (): void {
    currentCompany()->update(['workos_organization_id' => 'org_company']);

    $response = $this->get(route('auth.workos.platform.redirect'));

    $response->assertRedirectContains('api.workos.com/user_management/authorize');
    expect($response->headers->get('Location'))->not->toContain('organization_id');
});

it('rejects a callback whose state was not minted for this session', function (): void {
    Http::fake();

    $this->withSession(['workos_oauth_state' => 'expected-nonce'])
        ->get(route('auth.workos.callback', ['code' => 'abc', 'state' => 'forged']))
        ->assertRedirect(route('tenant.login', currentCompany()))
        ->assertSessionHasErrors('workos');

    expect(auth()->check())->toBeFalse();
});

it('signs in a pre-provisioned person and links the global account', function (): void {
    Role::factory()->create(['key' => 'employee', 'name' => 'Employee', 'is_protected' => true]);
    $person = User::factory()->create([
        'email' => 'nova.pessoa@example.com',
        'provider' => null,
        'provider_id' => null,
        'workos_user_id' => null,
    ]);

    Http::fake([
        'api.workos.com/user_management/authenticate' => Http::response([
            'user' => [
                'id' => 'user_01HX',
                'email' => 'Nova.Pessoa@example.com',
                'first_name' => 'Nova',
                'last_name' => 'Pessoa',
                'email_verified' => true,
            ],
        ]),
    ]);

    $state = app(OauthStateSigner::class)->issue('nonce-value');

    $this->withSession(['workos_oauth_state' => 'nonce-value'])
        ->get(route('auth.workos.callback', ['code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('dashboard'));

    $user = $person->fresh();
    $account = Account::query()->firstWhere('email', 'nova.pessoa@example.com');

    expect($user)->not->toBeNull()
        ->and($account)->not->toBeNull()
        ->and($user->account_id)->toBe($account->id)
        ->and($user->workos_user_id)->toBe('user_01HX')
        ->and($user->hasRole('employee'))->toBeTrue()
        ->and(auth()->id())->toBe($user->id);
});

it('refuses to adopt an existing account when the provider has not verified the email', function (): void {
    User::factory()->create(['email' => 'existing@example.com', 'provider' => null, 'provider_id' => null]);

    Http::fake([
        'api.workos.com/user_management/authenticate' => Http::response([
            'user' => [
                'id' => 'user_other',
                'email' => 'existing@example.com',
                'email_verified' => false,
            ],
        ]),
    ]);

    $state = app(OauthStateSigner::class)->issue('nonce-value');

    $this->withSession(['workos_oauth_state' => 'nonce-value'])
        ->get(route('auth.workos.callback', ['code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('tenant.login', currentCompany()))
        ->assertSessionHasErrors('workos');

    expect(auth()->check())->toBeFalse();
});

it('blocks a terminated person from signing in', function (): void {
    User::factory()->terminated()->create([
        'email' => 'gone@example.com',
        'provider' => 'workos',
        'provider_id' => 'user_gone',
    ]);

    Http::fake([
        'api.workos.com/user_management/authenticate' => Http::response([
            'user' => ['id' => 'user_gone', 'email' => 'gone@example.com', 'email_verified' => true],
        ]),
    ]);

    $state = app(OauthStateSigner::class)->issue('nonce-value');

    $this->withSession(['workos_oauth_state' => 'nonce-value'])
        ->get(route('auth.workos.callback', ['code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('tenant.login', currentCompany()));

    expect(auth()->check())->toBeFalse();
});

it('refuses to provision an unknown identity', function (): void {
    Http::fake([
        'api.workos.com/user_management/authenticate' => Http::response([
            'user' => ['id' => 'user_new', 'email' => 'stranger@example.com', 'email_verified' => true],
        ]),
    ]);

    $state = app(OauthStateSigner::class)->issue('nonce-value');

    $this->withSession(['workos_oauth_state' => 'nonce-value'])
        ->get(route('auth.workos.callback', ['code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('tenant.login', currentCompany()));

    expect(User::query()->where('email', 'stranger@example.com')->exists())->toBeFalse();
});

it('marks a configured identity as a platform administrator', function (): void {
    config()->set('oceanix.platform_admin_emails', ['platform@example.com']);
    User::factory()->create(['email' => 'platform@example.com']);

    Http::fake([
        'api.workos.com/user_management/authenticate' => Http::response([
            'user' => ['id' => 'user_platform', 'email' => 'platform@example.com', 'email_verified' => true],
        ]),
    ]);

    $state = app(OauthStateSigner::class)->issue('nonce-value');

    $this->withSession(['workos_oauth_state' => 'nonce-value'])
        ->get(route('auth.workos.callback', ['code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('dashboard'));

    expect(Account::query()->firstWhere('email', 'platform@example.com')?->is_platform_admin)->toBeTrue();
});

it('bootstraps a configured platform account without any company', function (): void {
    config()->set('oceanix.platform_admin_emails', ['bootstrap@example.com']);
    Company::query()->delete();
    app(TenantContext::class)->clear();

    Http::fake([
        'api.workos.com/user_management/authenticate' => Http::response([
            'user' => [
                'id' => 'user_bootstrap',
                'email' => 'bootstrap@example.com',
                'first_name' => 'Bootstrap',
                'last_name' => 'Admin',
                'email_verified' => true,
            ],
        ]),
    ]);

    $state = app(OauthStateSigner::class)->issue('nonce-value');

    $this->withSession([
        'workos_oauth_state' => 'nonce-value',
        'workos_login_mode' => 'platform',
    ])->get(route('auth.workos.callback', ['code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('platform.dashboard'));

    $account = Account::query()->firstWhere('email', 'bootstrap@example.com');

    expect($account?->is_platform_admin)->toBeTrue()
        ->and(session('platform_account_id'))->toBe($account?->id)
        ->and(auth()->check())->toBeFalse();

    $this->get(route('platform.dashboard'))->assertOk();
});

it('does not register the local sign-in bypass outside the local environment', function (): void {
    // The test environment is not `local`, so the route must not exist at all.
    expect(Route::has('auth.local'))->toBeFalse();

    $this->get('/auth/local')->assertNotFound();
});
