<?php

use App\Actions\Auth\AuthenticateSocialLogin;
use App\Models\Account;
use App\Models\PersonProductAccess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Oceanix\Platform\Auth\Data\SocialIdentity;
use Oceanix\Platform\Auth\Exceptions\SocialLoginProviderException;
use Tests\Support\AccountIntegrationFixture;

it('denies delivered block on web and Livewire including administrators', function (bool $administrator) {
    $this->withoutVite();
    $person = $administrator ? adminUser() : employeeUser();
    $response = $this->actingAs($person)->get(route('dashboard'))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    $this->withHeader('X-Livewire', 'true')->postJson(route('default-livewire.update'), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]])->assertOk();
    PersonProductAccess::create(['company_id' => $person->company_id, 'user_id' => $person->id, 'confirmed_enabled' => false, 'version' => 1, 'operation_id' => (string) Str::uuid()]);
    $this->get(route('dashboard'))->assertForbidden();
    Livewire::flushState();
    $this->withHeader('X-Livewire', 'true')->postJson(route('default-livewire.update'), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]])->assertForbidden();
})->with([false, true]);

it('authenticates exact linked stable identity but rejects collision and block before any first access side effect', function () {
    Http::preventStrayRequests();
    $person = employeeUser();
    $person->forceFill(['workos_user_id' => 'user_linked_login', 'first_access_at' => now()->subDays(5)])->save();
    $link = AccountIntegrationFixture::invitationLink($person)->fresh();
    $projection = PersonProductAccess::create(['company_id' => $person->company_id, 'user_id' => $person->id, 'confirmed_enabled' => true, 'version' => 1, 'operation_id' => (string) Str::uuid()]);
    $action = app(AuthenticateSocialLogin::class);
    $identity = new SocialIdentity('workos', 'user_linked_login', $person->email, 'Linked person', emailVerified: true);
    $before = $person->getRawOriginal();
    $roles = $person->roles()->pluck('roles.id')->all();
    expect(fn () => $action->handle(new SocialIdentity('workos', 'user_conflicting_login', $person->email, emailVerified: true)))->toThrow(SocialLoginProviderException::class);
    expect($person->fresh()->getRawOriginal())->toBe($before)->and(Account::count())->toBe(0);
    $projection->update(['confirmed_enabled' => false]);
    expect(fn () => $action->handle($identity))->toThrow(SocialLoginProviderException::class);
    expect($person->fresh()->getRawOriginal())->toBe($before)->and(Account::count())->toBe(0);
    $projection->update(['confirmed_enabled' => true]);
    $signedIn = $action->handle($identity);
    expect($signedIn->id)->toBe($person->id)->and($signedIn->workos_user_id)->toBe('user_linked_login')
        ->and($signedIn->first_access_at->toDateTimeString())->toBe($person->first_access_at->toDateTimeString())
        ->and($signedIn->status)->toBe($person->status)->and($signedIn->roles()->pluck('roles.id')->all())->toBe($roles)
        ->and($link->fresh()->getRawOriginal())->toBe($link->getRawOriginal())->and(Account::sole()->workos_user_id)->toBe('user_linked_login');
    Http::assertNothingSent();
});
