<?php

use App\Actions\Platform\EnterCompany;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\SocialLogin\OauthStateSigner;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('records first and last authorized tenant access', function () {
    $person = User::factory()->create(['email' => 'first@example.com', 'status' => UserStatus::Invited]);
    Http::fake(['*/user_management/authenticate' => Http::response(['user' => ['id' => 'user_first', 'email' => $person->email, 'email_verified' => true]])]);
    $this->travelTo(now()->startOfSecond());
    $first = now();
    $callback = function () {
        $this->withSession(['company_id' => currentCompany()->id, 'workos_oauth_state' => 'first-nonce'])
            ->get(route('auth.workos.callback', ['code' => 'abc', 'state' => app(OauthStateSigner::class)->issue('first-nonce')]))
            ->assertRedirect(route('dashboard'));
    };
    $callback();
    expect($person->fresh()->status)->toBe(UserStatus::Active)
        ->and($person->fresh()->first_access_at?->equalTo($first))->toBeTrue();
    auth()->logout();
    $this->travel(10)->minutes();
    $callback();
    expect($person->fresh()->first_access_at->equalTo($first))->toBeTrue()
        ->and($person->fresh()->last_access_at->greaterThan($first))->toBeTrue();
});

it('rejects blocked callback without activation', function ($status) {
    $person = User::factory()->create(['email' => 'blocked@example.com', 'status' => $status]);
    Http::fake(['*/user_management/authenticate' => Http::response(['user' => ['id' => 'user_blocked', 'email' => $person->email, 'email_verified' => true]])]);
    $this->withSession(['company_id' => currentCompany()->id, 'workos_oauth_state' => 'blocked-nonce'])
        ->get(route('auth.workos.callback', ['code' => 'abc', 'state' => app(OauthStateSigner::class)->issue('blocked-nonce')]))->assertSessionHasErrors('workos');
    expect($person->fresh()->status)->toBe($status)->and($person->fresh()->first_access_at)->toBeNull();
})->with([UserStatus::Suspended, UserStatus::Terminated]);

it('records authorized platform entry and tenant switching without treating grants as access', function () {
    $account = Account::factory()->platformAdmin()->create();
    $person = User::factory()->create(['account_id' => $account->id, 'status' => UserStatus::Invited]);
    $this->withSession(['platform_account_id' => $account->id]);
    $entered = app(EnterCompany::class)->handle(currentCompany());
    expect($entered->id)->toBe($person->id)->and($person->fresh()->first_access_at)->not->toBeNull()->and($person->fresh()->status)->toBe(UserStatus::Active);
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);
    $target = User::factory()->create(['account_id' => $account->id, 'status' => UserStatus::Invited]);
    app(TenantContext::class)->set($person->company);
    $this->post(route('company.switch', ['targetCompany' => $company]))->assertRedirect(route('dashboard', ['company' => $company]));
    expect($target->fresh()->first_access_at)->not->toBeNull()->and($target->fresh()->status)->toBe(UserStatus::Active);
});

it('rejects inactive accounts and invalid identity without recording access', function ($inactive, $verified) {
    $person = User::factory()->create(['email' => 'invalid@example.com', 'status' => UserStatus::Invited]);
    if ($inactive) {
        $account = Account::factory()->create(['email' => $person->email, 'status' => 'inactive']);
        $person->forceFill(['account_id' => $account->id])->save();
    }
    Http::fake(['*/user_management/authenticate' => Http::response(['user' => ['id' => 'user_invalid', 'email' => $person->email, 'email_verified' => $verified]])]);
    $this->withSession(['company_id' => currentCompany()->id, 'workos_oauth_state' => 'invalid-nonce'])
        ->get(route('auth.workos.callback', ['code' => 'abc', 'state' => app(OauthStateSigner::class)->issue('invalid-nonce')]))->assertSessionHasErrors('workos');
    expect(auth()->check())->toBeFalse()->and($person->fresh()->first_access_at)->toBeNull()->and($person->fresh()->status)->toBe(UserStatus::Invited);
})->with([[true, true], [false, false]]);

it('denies blocked platform entry without access evidence', function ($status) {
    $account = Account::factory()->platformAdmin()->create();
    $person = User::factory()->create(['account_id' => $account->id, 'status' => $status]);
    $this->withSession(['platform_account_id' => $account->id]);
    expect(fn () => app(EnterCompany::class)->handle(currentCompany()))->toThrow(HttpException::class);
    expect($person->fresh()->first_access_at)->toBeNull()->and($person->fresh()->status)->toBe($status);
})->with([UserStatus::Suspended, UserStatus::Terminated]);
