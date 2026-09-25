<?php

use App\Actions\ControlPlane\EnsureCompany;
use App\Models\AccountCompanyBinding;
use App\Models\Company;
use App\Models\ProvisioningReceipt;
use App\Models\ServicePrincipal;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
});

function provisionToken(array $abilities = ['companies:provision'], mixed $expires = null): string
{
    return ServicePrincipal::create(['name' => 'Account fixture', 'product' => 'compliance', 'environment' => app()->environment(), 'active' => true])->createToken('fixture', $abilities, $expires)->plainTextToken;
}
function provisionPayload(string $organization = 'org_receiver'): array
{
    return ['workos_organization_id' => $organization, 'name' => 'Offshore Receiver', 'slug' => 'offshore-receiver', 'actor_workos_user_id' => 'user_operator', 'correlation_id' => (string) Str::uuid()];
}
function provisionUrl(string $uuid): string
{
    return '/api/control-plane/v1/companies/'.$uuid;
}

it('SC-07 provisions a tenant with a scoped machine token and no human role grants', function () {
    $uuid = (string) Str::uuid();
    $key = (string) Str::uuid();
    $payload = provisionPayload();
    $before = Company::count();
    $context = app(TenantContext::class)->id();
    $this->withToken(provisionToken())->withHeader('Idempotency-Key', $key)->putJson(provisionUrl($uuid), $payload)->assertCreated()->assertJsonPath('account_company_uuid', $uuid)->assertJsonPath('workos_organization_id', 'org_receiver')->assertJsonPath('operation_id', $key)->assertJsonPath('outcome', 'created');
    expect(Company::count())->toBe($before + 1)->and(AccountCompanyBinding::count())->toBe(1)->and(ProvisioningReceipt::count())->toBe(1)->and(User::withoutGlobalScopes()->count())->toBe(0)->and(DB::table('role_user')->count())->toBe(0);
    expect(app(TenantContext::class)->id())->toBe($context);
    Http::assertNothingSent();
});

it('SC-08 adopts an existing tenant without rekeying or modifying published evidence', function () {
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_existing']);
    [$assignment] = trainableAssignment();
    $companyBefore = $company->fresh()->getAttributes();
    $assignmentBefore = $assignment->getAttributes();
    $roleCount = DB::table('roles')->count();
    $users = User::withoutGlobalScopes()->count();
    $uuid = (string) Str::uuid();
    $payload = provisionPayload('org_existing');
    $this->withToken(provisionToken())->withHeader('Idempotency-Key', (string) Str::uuid())->putJson(provisionUrl($uuid), $payload)->assertOk()->assertJsonPath('outcome', 'existing')->assertJsonPath('compliance_company_public_id', $company->public_id);
    expect($company->fresh()->getAttributes())->toBe($companyBefore)->and($assignment->fresh()->getAttributes())->toBe($assignmentBefore)->and(DB::table('roles')->count())->toBe($roleCount)->and(User::withoutGlobalScopes()->count())->toBe($users);
});

it('SC-08 SC-12 replays identical receipts and rejects changed payload under the same key', function () {
    $uuid = (string) Str::uuid();
    $key = (string) Str::uuid();
    $payload = provisionPayload();
    $this->withToken(provisionToken())->withHeader('Idempotency-Key', $key);
    $first = $this->putJson(provisionUrl($uuid), $payload)->assertCreated()->json();
    $this->putJson(provisionUrl($uuid), $payload)->assertStatus(201)->assertExactJson($first);
    $this->putJson(provisionUrl($uuid), [...$payload, 'name' => 'Changed'])->assertConflict();
    expect(Company::where('workos_organization_id', 'org_receiver')->count())->toBe(1)->and(ProvisioningReceipt::count())->toBe(1);
});

it('SC-11 rejects missing wrong scoped expired and human tokens without mutations', function () {
    $uuid = (string) Str::uuid();
    $payload = provisionPayload();
    $count = Company::count();
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson(provisionUrl($uuid), $payload)->assertUnauthorized();
    $this->withToken('wrong-token')->putJson(provisionUrl($uuid), $payload)->assertUnauthorized();
    $this->withToken(provisionToken(['other:scope']))->putJson(provisionUrl($uuid), $payload)->assertForbidden();
    $this->withToken(provisionToken(expires: now()->subMinute()))->putJson(provisionUrl($uuid), $payload)->assertUnauthorized();
    $human = adminUser();
    $this->withToken($human->createToken('human', ['companies:provision'])->plainTextToken)->putJson(provisionUrl($uuid), $payload)->assertForbidden();
    expect(Company::count())->toBe($count)->and(AccountCompanyBinding::count())->toBe(0);
    Http::assertNothingSent();
});

it('SC-11 rejects conflicting Account bindings and occupied slugs without modifying tenants', function () {
    $uuid = (string) Str::uuid();
    $payload = provisionPayload();
    $this->withToken(provisionToken())->withHeader('Idempotency-Key', (string) Str::uuid())->putJson(provisionUrl($uuid), $payload)->assertCreated();
    $company = Company::where('workos_organization_id', 'org_receiver')->sole();
    $before = $company->getAttributes();
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson(provisionUrl($uuid), [...$payload, 'workos_organization_id' => 'org_other'])->assertConflict();
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson(provisionUrl((string) Str::uuid()), [...$payload, 'workos_organization_id' => 'org_other'])->assertConflict();
    expect($company->fresh()->getAttributes())->toBe($before)->and(AccountCompanyBinding::count())->toBe(1)->and(ProvisioningReceipt::count())->toBe(1);
});

it('SC-11 rejects invalid identity and idempotency input before any tenant creation', function () {
    $count = Company::count();
    $this->withToken(provisionToken())->putJson(provisionUrl((string) Str::uuid()), provisionPayload())->assertUnprocessable();
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson(provisionUrl((string) Str::uuid()), [...provisionPayload(), 'workos_organization_id' => 'not-an-org'])->assertUnprocessable();
    expect(Company::count())->toBe($count)->and(ProvisioningReceipt::count())->toBe(0);
});

it('SC-09 returns a safe unavailable response if local provisioning fails', function () {
    $this->mock(EnsureCompany::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('private database detail'));
    $this->withToken(provisionToken())->withHeader('Idempotency-Key', (string) Str::uuid())->putJson(provisionUrl((string) Str::uuid()), provisionPayload())->assertStatus(503)->assertDontSee('private database detail')->assertJsonPath('error', 'provisioning_unavailable');
});
