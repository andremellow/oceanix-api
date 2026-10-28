<?php

use App\Actions\Auth\AuthenticatePlatformAccount;
use App\Actions\Platform\InvitePlatformAdministrator;
use App\Models\Account;
use App\Services\Platform\PlatformAccess;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Oceanix\Platform\Auth\Data\SocialIdentity;
use Oceanix\Platform\Auth\Exceptions\SocialLoginProviderException;
use Oceanix\Platform\Livewire\PlatformCompanies;
use Oceanix\Platform\Livewire\PlatformUsers;

function platformIdentityList(?array $identity = null): array
{
    return ['object' => 'list', 'data' => $identity === null ? [] : [$identity], 'list_metadata' => ['before' => null, 'after' => null]];
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['app.locale' => 'en', 'oceanix-platform.auth.workos.api_key' => 'sk_test']);
    $this->platformActor = Account::factory()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $this->platformActor->id, 'locale' => 'en']);
});

it('routes global users and companies to SDK classes with the existing shell', function () {
    Livewire::test('platform.users')->assertSee('Platform administrators');
    Livewire::test(PlatformCompanies::class)->assertSee('Companies');
    $this->get(route('platform.users'))->assertOk()->assertSee('Tasks')->assertSee('Platform administrators')->assertSeeLivewire(PlatformUsers::class);
    expect(app(Oceanix\Platform\Services\Platform\PlatformAccess::class))->toBeInstanceOf(PlatformAccess::class);
    expect(app(InvitePlatformAdministrator::class))->toBeInstanceOf(Oceanix\Platform\Actions\Platform\InvitePlatformAdministrator::class);
});

it('reuses a verified WorkOS account without sending an invitation', function () {
    $existing = Account::factory()->create(['email' => 'verified@example.com', 'workos_user_id' => null, 'provider' => null, 'provider_id' => null]);
    Http::fake(['api.workos.com/user_management/users*' => Http::response(platformIdentityList(['object' => 'user', 'id' => 'user_verified', 'email' => $existing->email, 'email_verified' => true]))]);

    Livewire::test(PlatformUsers::class)->set('administratorName', 'Verified Administrator')->set('administratorEmail', 'VERIFIED@example.com')
        ->call('inviteAdministrator')->assertHasNoErrors()
        ->assertSee('No invitation was sent.');

    expect($existing->fresh())->is_platform_admin->toBeTrue()->status->toBe('active')->workos_user_id->toBe('user_verified');
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

it('keeps an unverified existing account blocked until verified login', function () {
    Http::fake(['api.workos.com/user_management/users*' => Http::response(platformIdentityList(['object' => 'user', 'id' => 'user_pending', 'email' => 'pending@example.com', 'email_verified' => false]))]);
    Livewire::test(PlatformUsers::class)->set('administratorName', 'Pending Administrator')->set('administratorEmail', 'pending@example.com')
        ->call('inviteAdministrator')->assertHasNoErrors()->assertSee('Awaiting email verification');
    $account = Account::where('email', 'pending@example.com')->sole();
    expect($account->status)->toBe('pending_verification');
    Http::assertSentCount(1);
    $this->withSession(['platform_account_id' => $account->id])->get(route('platform.users'))->assertRedirect(route('platform.login'));
    expect(fn () => app(AuthenticatePlatformAccount::class)->handle(new SocialIdentity(provider: 'workos', providerId: 'user_pending', email: $account->email, name: $account->name, emailVerified: false)))
        ->toThrow(SocialLoginProviderException::class);
    $active = app(AuthenticatePlatformAccount::class)->handle(new SocialIdentity(provider: 'workos', providerId: 'user_pending', email: $account->email, name: $account->name, emailVerified: true));
    expect($active->id)->toBe($account->id)->and($active->status)->toBe('active');
});

it('does not grant administrator access on provider or identity errors', function (string $failure) {
    $email = 'target@example.com';
    $existing = Account::factory()->create(['email' => $email, 'is_platform_admin' => false, 'provider' => 'workos', 'provider_id' => $failure === 'conflict' ? 'user_other' : null, 'workos_user_id' => $failure === 'conflict' ? 'user_other' : null]);
    Http::fake(['api.workos.com/user_management/users*' => match ($failure) {
        'provider' => Http::response([], 503),
        'malformed' => Http::response(['data' => []]),
        default => Http::response(platformIdentityList(['object' => 'user', 'id' => 'user_target', 'email' => $email, 'email_verified' => true])),
    }]);
    Livewire::test(PlatformUsers::class)->set('administratorName', 'Target Administrator')->set('administratorEmail', $email)
        ->call('inviteAdministrator')->assertHasErrors('administratorRegistration');
    expect($existing->fresh()->is_platform_admin)->toBeFalse();
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
})->with(['provider', 'malformed', 'conflict']);

it('does not persist a new administrator when sending the invitation fails', function () {
    Http::fake(['api.workos.com/user_management/users*' => Http::response(platformIdentityList()), 'api.workos.com/user_management/invitations' => Http::response([], 503)]);
    Livewire::test(PlatformUsers::class)->set('administratorName', 'New Administrator')->set('administratorEmail', 'failed@example.com')
        ->call('inviteAdministrator')->assertHasErrors('administratorRegistration');
    expect(Account::where('email', 'failed@example.com')->exists())->toBeFalse();
});

it('validates administrator input before contacting WorkOS', function () {
    Livewire::test(PlatformUsers::class)->set('administratorEmail', 'invalid')->call('inviteAdministrator')
        ->assertHasErrors(['administratorName', 'administratorEmail']);
    Http::assertNothingSent();
});

it('revokes SDK actions even when the linked account relation was already loaded', function () {
    $person = adminUser();
    $person->update(['account_id' => $this->platformActor->id]);
    $person->load('account');
    $screen = Livewire::actingAs($person)->test(PlatformUsers::class)->set('administratorName', 'Must Fail')->set('administratorEmail', 'must-fail@example.com');
    $this->platformActor->update(['is_platform_admin' => false]);
    $screen->call('inviteAdministrator')->assertForbidden();
    Http::assertNothingSent();
});

it('delegates company operations while retaining the Compliance company screen', function () {
    foreach (['EnterCompany', 'ChangeCompanyStatus', 'GrantPlatformCompanyAccess', 'InviteCompanyAdministrator'] as $action) {
        expect(app('App\\Actions\\Platform\\'.$action))->toBeInstanceOf('Oceanix\\Platform\\Actions\\Platform\\'.$action);
    }
    Livewire::test('platform.company', ['company' => currentCompany()])->assertSee('Company administrators')->assertSee('Courses');
});

it('uses the existing WorkOS service configuration for SDK registration', function () {
    config(['services.workos.api_key' => 'synthetic-service-config', 'oceanix-platform.auth.workos.api_key' => null]);
    Http::fake([
        'api.workos.com/user_management/users*' => Http::response(platformIdentityList()),
        'api.workos.com/user_management/invitations' => Http::response(['id' => 'inv_service_config'], 201),
    ]);
    Livewire::test(PlatformUsers::class)->set('administratorName', 'Configured Administrator')->set('administratorEmail', 'configured@example.com')
        ->call('inviteAdministrator')->assertHasNoErrors()->assertSee('Platform administrator invitation sent.');
    expect(Account::where('email', 'configured@example.com')->sole()->is_platform_admin)->toBeTrue();
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer synthetic-service-config') && $request->method() === 'POST');
});
