<?php

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Actions\ControlPlane\AuthorizeProductActor;
use App\Actions\People\RegisterPerson;
use App\Enums\Permission;
use App\Models\AccountCompanyBinding;
use App\Models\ServicePrincipal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('requires a durable exact registration request and current permission for actor proofs', function () {
    Http::preventStrayRequests();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_proof']);
    $binding = AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => 'org_proof']);
    $principal = ServicePrincipal::create(['name' => 'Synthetic', 'product' => 'compliance', 'environment' => 'testing', 'active' => true, 'capabilities' => ['actors.authorize'], 'company_uuids' => [$binding->account_company_uuid]]);
    $actor = userWithPermissions([Permission::PeopleManage]);
    $actor->update(['workos_user_id' => 'user_proof_actor']);
    Http::fake(['account.example.test/*' => Http::failedConnection()]);
    $operation = app(RegisterPerson::class)->handle($actor, (string) Str::uuid(), 'Proof', 'proof@example.test');
    $input = $operation->payload + ['payload_hash' => $operation->payload_hash];
    $proof = app(AuthorizeProductActor::class)->handle($principal, $input);
    expect($proof['payload_hash'])->toBe($operation->payload_hash)->and($operation->user_id)->toBeNull();
    $foreign = $operation->payload;
    $foreign['payload']['email'] = 'other@example.test';
    expect(fn () => app(AuthorizeProductActor::class)->handle($principal, $foreign + ['payload_hash' => ApplyProductOperation::hash($foreign)]))->toThrow(HttpException::class);
    $mapping = $operation->payload;
    $mapping['payload'] = ['registration_mode' => 'confirmed-first-mapping', 'registration_source' => 'form', 'local_person_id' => $actor->id, 'request_payload_hash' => $operation->payload_hash];
    expect(fn () => app(AuthorizeProductActor::class)->handle($principal, $mapping + ['payload_hash' => ApplyProductOperation::hash($mapping)]))->toThrow(HttpException::class);
    $actor->roles()->detach();
    expect(fn () => app(AuthorizeProductActor::class)->handle($principal, $input))->toThrow(HttpException::class);
});
