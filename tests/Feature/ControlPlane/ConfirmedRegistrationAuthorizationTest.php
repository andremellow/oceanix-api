<?php

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Actions\ControlPlane\AuthorizeProductActor;
use App\Actions\People\RegisterPerson;
use App\Enums\Permission;
use App\Models\AccountCompanyBinding;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\ServicePrincipal;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('requires a durable exact registration request and current permission for actor proofs', function () {
    Http::preventStrayRequests();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_proof']);
    $binding = AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => 'org_proof']);
    $principal = ServicePrincipal::create(['name' => 'Synthetic', 'product' => 'compliance', 'environment' => 'testing', 'active' => true, 'capabilities' => [], 'company_uuids' => [$binding->account_company_uuid]]);
    $actor = userWithPermissions([Permission::PeopleManage]);
    $actor->update(['workos_user_id' => 'user_proof_actor']);
    Http::fake(['account.example.test/*' => Http::failedConnection()]);
    $operation = app(RegisterPerson::class)->handle($actor, (string) Str::uuid(), 'Proof', 'proof@example.test');
    $input = $operation->payload + ['payload_hash' => $operation->payload_hash];
    $token = $principal->createToken('grantless', [])->plainTextToken;
    $this->withToken($token)->postJson('/api/control-plane/v2/actor-authorizations', $input)->assertOk()->assertJsonPath('actor_assertion.payload_hash', $operation->payload_hash);
    $proof = app(AuthorizeProductActor::class)->handle($principal, $input);
    expect($proof['payload_hash'])->toBe($operation->payload_hash)->and($operation->user_id)->toBeNull();
    $foreign = $operation->payload;
    $foreign['payload']['email'] = 'other@example.test';
    expect(fn () => app(AuthorizeProductActor::class)->handle($principal, $foreign + ['payload_hash' => ApplyProductOperation::hash($foreign)]))->toThrow(HttpException::class);
    $mapping = $operation->payload;
    $mapping['payload'] = ['registration_mode' => 'confirmed-first-mapping', 'registration_source' => 'form', 'local_person_id' => $actor->id, 'request_payload_hash' => $operation->payload_hash];
    expect(fn () => app(AuthorizeProductActor::class)->handle($principal, $mapping + ['payload_hash' => ApplyProductOperation::hash($mapping)]))->toThrow(HttpException::class);
    $actor->roles()->detach();
    $this->postJson('/api/control-plane/v2/actor-authorizations', $input)->assertForbidden();
    expect(fn () => app(AuthorizeProductActor::class)->handle($principal, $input))->toThrow(HttpException::class);
});

it('authorizes only the exact linked pending registration mapping proof', function () {
    seedAccessCatalog();
    Http::preventStrayRequests();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    currentCompany()->update(['workos_organization_id' => 'org_linked_proof']);
    $binding = AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => currentCompany()->id, 'workos_organization_id' => 'org_linked_proof']);
    $principal = ServicePrincipal::create(['name' => 'Synthetic', 'product' => 'compliance', 'environment' => 'testing', 'active' => true, 'capabilities' => [], 'company_uuids' => [$binding->account_company_uuid]]);
    $actor = userWithPermissions([Permission::PeopleManage]);
    $actor->update(['workos_user_id' => 'user_linked_actor']);
    Http::fake(['account.example.test/*' => function ($request) {
        if (str_ends_with($request->url(), '/local-person')) {
            return Http::failedConnection();
        }
        $data = $request->data();

        return Http::response(array_intersect_key($data, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['shared_person_id' => '11111111-1111-4111-8111-111111111111', 'company_member_id' => '22222222-2222-4222-8222-222222222222', 'workos_user_id' => 'user_linked_person', 'workos_membership_id' => 'om_linked_person', 'membership_status' => 'pending', 'registration_source' => 'form', 'actor_workos_user_id' => $data['actor_workos_user_id'], 'request_payload_hash' => ApplyProductOperation::hash($data), 'registration_mode' => 'confirmed-first', 'mapping_complete' => false]]);
    }]);
    $operation = app(RegisterPerson::class)->handle($actor, (string) Str::uuid(), 'Linked pending', 'linked@example.test');
    expect($operation->state)->toBe('mapping_pending')->and($operation->user_id)->not->toBeNull();
    $mapping = $operation->payload;
    $state = $operation->receipt['registration']['confirmed_state'];
    $mapping['payload'] = array_intersect_key($state, array_flip(['request_payload_hash', 'registration_source', 'shared_person_id', 'company_member_id', 'workos_user_id', 'workos_membership_id'])) + ['registration_mode' => 'confirmed-first-mapping', 'local_person_id' => $operation->user_id];
    $before = $operation->fresh()->getRawOriginal();
    $links = PersonAccountLink::count();
    $positive = app(AuthorizeProductActor::class)->handle($principal, $mapping + ['payload_hash' => ApplyProductOperation::hash($mapping)]);
    expect($positive['payload_hash'])->toBe(ApplyProductOperation::hash($mapping));
    foreach (['shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid(), 'workos_user_id' => 'user_foreign', 'workos_membership_id' => 'om_foreign', 'registration_source' => 'import', 'request_payload_hash' => str_repeat('0', 64)] as $key => $value) {
        $foreign = $mapping;
        $foreign['payload'][$key] = $value;
        expect(fn () => app(AuthorizeProductActor::class)->handle($principal, $foreign + ['payload_hash' => ApplyProductOperation::hash($foreign)]))->toThrow(HttpException::class);
    }
    expect($operation->fresh()->getRawOriginal())->toBe($before)->and(PersonAccountLink::count())->toBe($links)->and(User::findOrFail($operation->user_id)->roles()->count())->toBe(0)->and(PersonProductAccess::where('user_id', $operation->user_id)->sole()->confirmed_enabled)->toBeFalse();
});
