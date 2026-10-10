<?php

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Actions\ControlPlane\AuthorizeProductActor;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\AccountCompanyBinding;
use App\Models\Company;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\ProductOperationReceipt;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AccountIntegrationFixture;
use Tests\Support\AccountIntegrationFixture as Fixture;

it('replays receipt without replacing newer access', function () {
    [$company, $person, $principal, $token, $envelope] = Fixture::receiver();
    $url = '/api/control-plane/v2/operations/';
    $this->withToken($token)->withHeader('Idempotency-Key', $envelope['operation_id']);
    $receipt = $this->putJson($url.$envelope['operation_id'], $envelope)->assertCreated()->assertJsonPath('outcome', 'confirmed')->json();
    $next = array_replace($envelope, ['operation_id' => (string) Str::uuid(), 'kind' => 'person.access', 'resource_sequence' => 2, 'payload' => array_replace($envelope['payload'], ['enabled' => false])]);
    $this->withHeader('Idempotency-Key', $next['operation_id'])->putJson($url.$next['operation_id'], $next)->assertCreated();
    $this->withHeader('Idempotency-Key', $envelope['operation_id'])->putJson($url.$envelope['operation_id'], $envelope)->assertExactJson($receipt);
    expect(PersonProductAccess::sole()->confirmed_enabled)->toBeFalse()->and(PersonAccountLink::count())->toBe(1)->and(ProductOperationReceipt::count())->toBe(2);
    $this->getJson($url.$envelope['operation_id'])->assertExactJson($receipt);
    $this->putJson($url.$envelope['operation_id'], array_replace($envelope, ['payload' => array_replace($envelope['payload'], ['enabled' => false])]))->assertConflict();
    Http::assertNothingSent();
});

it('enforces principal capability actor and receipt bindings', function () {
    [$company, $person, $principal, $token, $envelope] = Fixture::receiver();
    $url = '/api/control-plane/v2/operations/'.$envelope['operation_id'];
    $this->withToken($principal->createToken('wildcard', ['*'])->plainTextToken)->withHeader('Idempotency-Key', $envelope['operation_id'])->putJson($url, $envelope)->assertForbidden();
    $this->withToken($token)->putJson($url, array_replace($envelope, ['workos_organization_id' => 'org_wrong']))->assertConflict();
    $principal->update(['company_uuids' => []]);
    $this->putJson($url, $envelope)->assertForbidden();
    expect(PersonAccountLink::count())->toBe(0)->and(PersonProductAccess::count())->toBe(0);
});

it('provisions an Account-origin person once without email merge or invitation', function () {
    [$company, $existing, $principal, $token, $input] = AccountIntegrationFixture::receiver();
    $input['payload']['local_person_id'] = null;
    $input['payload']['name'] = 'Central provisioned person';
    $input['payload']['email'] = 'central-provision@example.test';
    $input['payload']['workos_user_id'] = 'user_central_provision';
    $url = '/api/control-plane/v2/operations/'.$input['operation_id'];
    $response = $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson($url, $input)->assertSuccessful();
    $localId = $response->json('confirmed_state.local_person_id');
    $person = User::findOrFail($localId);
    expect($person->name)->toBe('Central provisioned person')->and($person->workos_invitation_id)->toBeNull()->and($person->first_access_at)->toBeNull();
    $this->putJson($url, $input)->assertOk()->assertExactJson($response->json());
    expect(User::where('email', $input['payload']['email'])->count())->toBe(1);
    $collision = array_replace($input, ['operation_id' => (string) Str::uuid(), 'subject_id' => (string) Str::uuid()]);
    $this->withHeader('Idempotency-Key', $collision['operation_id'])->putJson('/api/control-plane/v2/operations/'.$collision['operation_id'], $collision)->assertConflict();
    expect(User::where('email', $input['payload']['email'])->count())->toBe(1);
    Http::assertNothingSent();
});

it('reauthorizes product actor against current local permission status company and confirmed access', function () {
    [$company, $target, $principal, $token, $input] = AccountIntegrationFixture::receiver();
    config(['services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    $actor = userWithPermissions([Permission::PeopleManage]);
    $actor->forceFill(['workos_user_id' => 'user_callback_actor'])->save();
    $input['kind'] = 'person.access.remove';
    $input['actor_workos_user_id'] = $actor->workos_user_id;
    $input['payload_hash'] = ApplyProductOperation::hash($input);
    $action = app(AuthorizeProductActor::class);
    $proof = $action->handle($principal, $input);
    expect($proof['actor_workos_user_id'])->toBe($actor->workos_user_id)->and($proof['payload_hash'])->toBe($input['payload_hash']);
    $actor->roles()->update(['archived_at' => now()]);
    expect(fn () => $action->handle($principal, $input))->toThrow(HttpException::class);
    $actor->roles()->update(['archived_at' => null]);
    expect($action->handle($principal, $input)['actor_workos_user_id'])->toBe($actor->workos_user_id);
    PersonProductAccess::create(['company_id' => $company->id, 'user_id' => $actor->id, 'confirmed_enabled' => false, 'version' => 1, 'operation_id' => (string) Str::uuid()]);
    expect(fn () => $action->handle($principal, $input))->toThrow(HttpException::class);
    PersonProductAccess::where('user_id', $actor->id)->update(['confirmed_enabled' => true]);
    $actor->update(['status' => UserStatus::Suspended]);
    expect(fn () => $action->handle($principal, $input))->toThrow(HttpException::class);
    $actor->update(['status' => UserStatus::Active]);
    $input['workos_organization_id'] = 'org_wrong_callback';
    unset($input['payload_hash']);
    $input['payload_hash'] = ApplyProductOperation::hash($input);
    expect(fn () => $action->handle($principal, $input))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

it('executes authenticated v2 company provisioning ordering conflict and exact replay atomically', function () {
    [$existing, $person, $principal, $token, $input] = Fixture::receiver();
    $uuid = (string) Str::uuid();
    $principal->update(['company_uuids' => [...$principal->company_uuids, $uuid]]);
    $input = array_replace($input, ['kind' => 'company.provision', 'account_company_uuid' => $uuid, 'workos_organization_id' => 'org_v2_company', 'subject_id' => $uuid, 'payload' => ['name' => 'V2 Company', 'slug' => 'v2-company', 'enabled' => true]]);
    $base = '/api/control-plane/v2/operations/';
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id']);
    $created = $this->putJson($base.$input['operation_id'], $input)->assertCreated()->assertJsonPath('confirmed_state.enabled', true)->json();
    $company = Company::where('workos_organization_id', 'org_v2_company')->sole();
    expect($created['confirmed_state']['local_company_id'])->toBe($company->public_id)->and($company->compliance_access_version)->toBe(1)
        ->and(AccountCompanyBinding::where('account_company_uuid', $uuid)->sole()->compliance_company_id)->toBe($company->id);
    $this->putJson($base.$input['operation_id'], $input)->assertOk()->assertExactJson($created);
    $this->putJson($base.$input['operation_id'], array_replace($input, ['payload' => array_replace($input['payload'], ['name' => 'Conflicting'])]))->assertConflict();
    $disabled = array_replace($input, ['operation_id' => (string) Str::uuid(), 'kind' => 'company.access', 'subject_id' => $company->public_id, 'resource_sequence' => 2, 'payload' => ['enabled' => false]]);
    $this->withHeader('Idempotency-Key', $disabled['operation_id'])->putJson($base.$disabled['operation_id'], $disabled)->assertCreated()->assertJsonPath('confirmed_state.enabled', false);
    $stale = array_replace($disabled, ['operation_id' => (string) Str::uuid()]);
    $this->withHeader('Idempotency-Key', $stale['operation_id'])->putJson($base.$stale['operation_id'], $stale)->assertConflict();
    expect(ProductOperationReceipt::where('company_id', $company->id)->count())->toBe(2)->and($company->fresh()->compliance_access_enabled)->toBeFalse()->and($company->fresh()->compliance_access_version)->toBe(2);
    $enabled = array_replace($disabled, ['operation_id' => (string) Str::uuid(), 'resource_sequence' => 3, 'payload' => ['enabled' => true]]);
    $this->withHeader('Idempotency-Key', $enabled['operation_id'])->putJson($base.$enabled['operation_id'], $enabled)->assertCreated()->assertJsonPath('confirmed_state.enabled', true);
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson($base.$input['operation_id'], $input)->assertExactJson($created);
    $this->getJson($base.$disabled['operation_id'])->assertOk()->assertJsonPath('confirmed_state.enabled', false);
    expect($company->fresh()->compliance_access_version)->toBe(3)->and($company->fresh()->compliance_access_enabled)->toBeTrue()->and(ProductOperationReceipt::where('company_id', $company->id)->count())->toBe(3);
    Http::assertNothingSent();
});
