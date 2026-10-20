<?php

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Actions\ControlPlane\AuthorizeProductActor;
use App\Actions\Platform\GrantPlatformCompanyAccess;
use App\Actions\Platform\InviteCompanyAdministrator;
use App\Models\Account;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Account\AccountUserClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AccountIntegrationFixture as Fixture;

it('SC-23 confirms platform membership through Account before granting local administrator role', function () {
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_platform']);
    AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => $company->workos_organization_id]);
    $platform = Account::factory()->create(['is_platform_admin' => true, 'status' => 'active', 'workos_user_id' => 'user_platform_actor']);
    session(['platform_account_id' => $platform->id]);
    config(['services.account.invitation_ownership_enabled' => true, 'services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
    Http::preventStrayRequests();
    Http::fake(['account.example.test/*' => function ($request) {
        $input = $request->data();
        expect($input['kind'])->toBe('person.membership.ensure')->and($input['payload']['actor_context'])->toBe('platform-account');
        $person = User::whereKey($input['payload']['local_person_id'])->sole();
        expect($person->roles()->where('key', 'admin')->exists())->toBeFalse();

        return Http::response(array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['workos_membership_id' => 'om_platform', 'membership_status' => 'active', 'local_person_id' => (string) $input['payload']['local_person_id'], 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid(), 'workos_user_id' => $person->workos_user_id]]);
    }]);
    $person = app(GrantPlatformCompanyAccess::class)->handle($company);
    expect($person->roles()->where('key', 'admin')->exists())->toBeTrue();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});

it('SC-21 SC-23 rejects a revoked platform actor on the exact persisted operation', function () {
    [$company,$person,$principal,$token,$input] = Fixture::receiver();
    $account = Account::factory()->create(['is_platform_admin' => true, 'status' => 'active', 'workos_user_id' => 'user_platform_proof']);
    $input['kind'] = 'person.membership.ensure';
    $input['actor_workos_user_id'] = $account->workos_user_id;
    $input['payload'] = ['actor_context' => 'platform-account', 'platform_account_id' => $account->id, 'local_person_id' => $person->id];
    AccountRequestOperation::create(['id' => $input['operation_id'], 'company_id' => $company->id, 'user_id' => $person->id, 'actor_id' => null, 'kind' => $input['kind'], 'actor_workos_user_id' => $account->workos_user_id, 'payload' => $input, 'payload_hash' => ApplyProductOperation::hash($input)]);
    config(['services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
    $proof = app(AuthorizeProductActor::class)->handle($principal, $input + ['payload_hash' => ApplyProductOperation::hash($input)]);
    expect($proof['operation_id'])->toBe($input['operation_id']);
    $account->update(['is_platform_admin' => false]);
    expect(fn () => app(AuthorizeProductActor::class)->handle($principal, $input + ['payload_hash' => ApplyProductOperation::hash($input)]))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

it('SC-23 keeps the original membership envelope after the confirmed identity becomes known', function () {
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_platform']);
    AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => $company->workos_organization_id]);
    $platform = Account::factory()->platformAdmin()->create();
    $person = User::factory()->create(['company_id' => $company->id, 'workos_user_id' => null]);
    config(['services.account.invitation_ownership_enabled' => true, 'services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
    $stored = null;
    Http::fake(['account.example.test/*' => function ($request) use (&$stored, $person) {
        if ($request->method() === 'GET') {
            return Http::response($stored);
        }
        $input = $request->data();
        expect($input['payload']['workos_user_id'])->toBeNull();
        $stored = array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['local_person_id' => (string) $person->id, 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid(), 'workos_user_id' => 'user_new_platform', 'workos_membership_id' => 'om_new_platform', 'membership_status' => 'pending']];

        return Http::response($stored);
    }]);
    $client = app(AccountUserClient::class);
    $client->platformOperation($platform, $company, $person, 'person.membership.ensure');
    $original = AccountRequestOperation::sole();
    $person->refresh()->update(['invitation_generation' => 99]);
    $client->platformOperation($platform, $company, $person->fresh(), 'person.membership.ensure');
    expect(AccountRequestOperation::count())->toBe(1)->and($original->fresh()->payload_hash)->toBe($original->payload_hash)->and($original->payload['payload']['workos_user_id'])->toBeNull();
    Http::assertSentCount(2);
});

it('SC-23 rechecks platform authority after membership confirmation before local role and success audit', function () {
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_platform']);
    $platform = Account::factory()->platformAdmin()->create();
    session(['platform_account_id' => $platform->id]);
    Fixture::fakePlatformConnection($company);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['account.example.test/*' => function ($request) use ($platform) {
        $input = $request->data();
        $platform->update(['is_platform_admin' => false]);

        return Http::response(array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['local_person_id' => (string) $input['payload']['local_person_id'], 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid(), 'workos_user_id' => $input['payload']['workos_user_id'], 'workos_membership_id' => 'om_platform', 'membership_status' => 'active']]);
    }]);
    expect(fn () => app(GrantPlatformCompanyAccess::class)->handle($company))->toThrow(HttpException::class);
    expect(User::where('account_id', $platform->id)->sole()->roles()->where('key', 'admin')->exists())->toBeFalse()->and(AuditLog::where('action', 'platform.company_admin_access_granted')->exists())->toBeFalse();
});

it('SC-17 SC-23 recovers the same invite intent after response loss and a newer local generation', function () {
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_platform']);
    $platform = Account::factory()->platformAdmin()->create();
    $person = User::factory()->create(['company_id' => $company->id, 'workos_user_id' => 'user_platform_target']);
    $link = Fixture::invitationLink($person);
    AccountCompanyBinding::create(['account_company_uuid' => $link->account_company_uuid, 'compliance_company_id' => $company->id, 'workos_organization_id' => $company->workos_organization_id]);
    config(['services.account.invitation_ownership_enabled' => true, 'services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
    $stored = null;
    Http::fake(['account.example.test/*' => function ($request) use (&$stored) {
        if ($request->method() === 'GET') {
            return Http::response($stored);
        }
        $input = $request->data();
        $stored = array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['sent' => true]];
        throw new ConnectionException('Synthetic lost invitation acknowledgement');
    }]);
    $client = app(AccountUserClient::class);
    expect(fn () => $client->platformOperation($platform, $company, $person, 'person.invite'))->toThrow(ConnectionException::class);
    $original = AccountRequestOperation::sole();
    $person->update(['invitation_generation' => 41]);
    $result = $client->platformOperation($platform, $company, $person->fresh(), 'person.invite');
    expect($result['sent'])->toBeTrue()->and(AccountRequestOperation::count())->toBe(1)->and($original->fresh()->state)->toBe('confirmed')->and($original->fresh()->payload_hash)->toBe($original->payload_hash);
    expect(collect(Http::recorded())->filter(fn ($record) => $record[0]->method() === 'PUT')->count())->toBe(0);
});

it('SC-23 reports an unconfirmed platform invitation honestly after membership is linked', function () {
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_platform']);
    $platform = Account::factory()->platformAdmin()->create();
    session(['platform_account_id' => $platform->id]);
    Fixture::fakePlatformConnection($company);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['account.example.test/*' => function ($request) {
        $input = $request->data();
        $state = $input['kind'] === 'person.membership.ensure' ? ['local_person_id' => (string) $input['payload']['local_person_id'], 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid(), 'workos_user_id' => 'user_pending_platform', 'workos_membership_id' => 'om_pending_platform', 'membership_status' => 'pending'] : [];

        return Http::response(array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => $input['kind'] === 'person.membership.ensure' ? 'confirmed' : 'pending', 'confirmed_state' => $state]);
    }]);
    expect(fn () => app(InviteCompanyAdministrator::class)->handle($company, 'Pending administrator', 'pending-platform@example.test'))->toThrow(RuntimeException::class, 'The invitation is awaiting confirmation from Account.');
    $person = User::where('email', 'pending-platform@example.test')->sole();
    expect($person->hasRole('admin'))->toBeTrue()->and($person->workos_invitation_id)->toBeNull()->and(AccountRequestOperation::where('kind', 'person.invite')->sole()->state)->toBe('pending');
});
