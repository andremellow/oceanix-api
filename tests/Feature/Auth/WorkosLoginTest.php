<?php

use App\Models\Role;
use App\Models\User;
use App\Services\SocialLogin\OauthStateSigner;
use Illuminate\Support\Facades\Http;

it('shows the sign-in page to guests', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee(__('ui.login_action'));
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

it('rejects a callback whose state was not minted for this session', function (): void {
    Http::fake();

    $this->withSession(['workos_oauth_state' => 'expected-nonce'])
        ->get(route('auth.workos.callback', ['code' => 'abc', 'state' => 'forged']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('workos');

    expect(auth()->check())->toBeFalse();
});

it('signs in and provisions a local user from a verified identity', function (): void {
    Role::factory()->create(['key' => 'employee', 'name' => 'Employee', 'is_protected' => true]);

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

    $user = User::query()->firstWhere('email', 'nova.pessoa@example.com');

    expect($user)->not->toBeNull()
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
        ->assertRedirect(route('login'))
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
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('refuses to provision an unknown identity when auto-provisioning is off', function (): void {
    config()->set('oceanix.auto_provision_users', false);

    Http::fake([
        'api.workos.com/user_management/authenticate' => Http::response([
            'user' => ['id' => 'user_new', 'email' => 'stranger@example.com', 'email_verified' => true],
        ]),
    ]);

    $state = app(OauthStateSigner::class)->issue('nonce-value');

    $this->withSession(['workos_oauth_state' => 'nonce-value'])
        ->get(route('auth.workos.callback', ['code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('login'));

    expect(User::query()->where('email', 'stranger@example.com')->exists())->toBeFalse();
});
