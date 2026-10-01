<?php

use App\Actions\Auth\RecordTenantAccess;
use App\Actions\Platform\CreateCompany;
use App\Actions\Platform\EnterCompany;
use App\Actions\Tenancy\SwitchCompany;
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

it('cleans callback authentication when access recording fails after login', function () {
    $person = User::factory()->create(['email' => 'record-failure@example.com', 'status' => UserStatus::Invited]);
    Http::fake(['*/user_management/authenticate' => Http::response(['user' => ['id' => 'user_record_failure', 'email' => $person->email, 'email_verified' => true]])]);
    $this->mock(RecordTenantAccess::class)->shouldReceive('handle')->once()->andReturnUsing(function ($loggedIn) use ($person) {
        expect(auth()->id())->toBe($person->id)->and(session('company_id'))->toBe($person->company_id);
        throw new RuntimeException('access persistence unavailable');
    });
    $this->withSession(['company_id' => currentCompany()->id, 'workos_oauth_state' => 'failure-nonce'])->get(route('auth.workos.callback', ['code' => 'abc', 'state' => app(OauthStateSigner::class)->issue('failure-nonce')]))->assertSessionHasErrors('workos')->assertSessionMissing('company_id');
    expect(auth()->check())->toBeFalse()->and($person->fresh()->first_access_at)->toBeNull()->and($person->fresh()->last_access_at)->toBeNull()->and($person->fresh()->status)->toBe(UserStatus::Invited);
});

it('restores context and clears authenticated tenant on failed entry or switch recording', function ($switch) {
    $previous = currentCompany();
    $account = Account::factory()->platformAdmin()->create();
    $source = User::factory()->create(['account_id' => $account->id]);
    $targetCompany = Company::factory()->create();
    app(TenantContext::class)->set($targetCompany);
    $target = User::factory()->create(['account_id' => $account->id, 'status' => UserStatus::Invited]);
    app(TenantContext::class)->set($previous);
    $this->withSession(['platform_account_id' => $account->id, 'company_id' => $previous->id]);
    $this->actingAs($source);
    $this->mock(RecordTenantAccess::class)->shouldReceive('handle')->once()->andReturnUsing(function ($person) use ($target) {
        expect(auth()->id())->toBe($target->id)->and(session('company_id'))->toBe($target->company_id)->and(app(TenantContext::class)->id())->toBe($target->company_id);
        throw new RuntimeException('recording failed');
    });
    expect(fn () => $switch ? app(SwitchCompany::class)->handle($source, $targetCompany) : app(EnterCompany::class)->handle($targetCompany))->toThrow(RuntimeException::class);
    expect(auth()->check())->toBeFalse()->and(session('company_id'))->toBeNull()->and(app(TenantContext::class)->id())->toBe($previous->id)->and(User::withoutGlobalScope('company')->find($target->id)->first_access_at)->toBeNull()->and(User::withoutGlobalScope('company')->find($target->id)->status)->toBe(UserStatus::Invited);
})->with([false, true]);

it('denies blocked target switching and preserves the allowed current session', function ($status) {
    $previous = currentCompany();
    $account = Account::factory()->create();
    $source = User::factory()->create(['account_id' => $account->id]);
    $targetCompany = Company::factory()->create();
    app(TenantContext::class)->set($targetCompany);
    $target = User::factory()->create(['account_id' => $account->id, 'status' => $status]);
    app(TenantContext::class)->set($previous);
    $this->actingAs($source)->withSession(['company_id' => $previous->id]);
    expect(fn () => app(SwitchCompany::class)->handle($source, $targetCompany))->toThrow(HttpException::class);
    expect(auth()->id())->toBe($source->id)->and(session('company_id'))->toBe($previous->id)->and(app(TenantContext::class)->id())->toBe($previous->id)->and(User::withoutGlobalScope('company')->find($target->id)->first_access_at)->toBeNull()->and(User::withoutGlobalScope('company')->find($target->id)->status)->toBe($status);
})->with([UserStatus::Suspended, UserStatus::Terminated]);

it('keeps a supplied company owner invited until authorized entry', function () {
    $account = Account::factory()->platformAdmin()->create(['workos_user_id' => 'user_owner']);
    $company = app(CreateCompany::class)->handle('New owner company', owner: $account);
    $person = User::withoutGlobalScope('company')->where('company_id', $company->id)->where('account_id', $account->id)->firstOrFail();
    app(TenantContext::class)->set($company);
    expect($person->status)->toBe(UserStatus::Invited)->and($person->account_id)->toBe($account->id)
        ->and($person->workos_user_id)->toBe('user_owner')->and($person->roles()->where('key', 'admin')->exists())->toBeTrue()
        ->and($person->first_access_at)->toBeNull()->and($person->last_access_at)->toBeNull();
    $this->withSession(['platform_account_id' => $account->id]);
    app(EnterCompany::class)->handle($company);
    expect($person->fresh()->status)->toBe(UserStatus::Active)->and($person->fresh()->first_access_at)->not->toBeNull()
        ->and($person->fresh()->last_access_at)->not->toBeNull()->and($person->fresh()->account_id)->toBe($account->id)
        ->and($person->roles()->where('key', 'admin')->exists())->toBeTrue();
});
