<?php

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Enums\Permission;
use App\Models\AccountRequestOperation;
use App\Models\Company;
use App\Models\ProductOperationReceipt;
use App\Models\User;
use App\Services\Account\AccountUserClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AccountIntegrationFixture as Fixture;

it('SC-20 ignores deprecated company lists while preserving exact binding and receipts', function () {
    [$company, $person, $principal, $token, $input] = Fixture::receiver();
    $principal->update(['company_uuids' => []]);
    $url = '/api/control-plane/v2/operations/'.$input['operation_id'];
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson($url, $input)->assertSuccessful();
    $principal->update(['company_uuids' => ['obsolete-company']]);
    $this->getJson($url)->assertOk();
    $input['operation_id'] = (string) Str::uuid();
    $input['workos_organization_id'] = 'org_foreign';
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertConflict();
});

it('TA-01 verifies the real persisted mapping source callback and current permission', function () {
    [$company,$person,$principal,$token,$input] = Fixture::receiver();
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->update(['workos_user_id' => 'user_mapping_authority']);
    $source = $input;
    $source['kind'] = 'person.invitation.reconcile';
    $source['subject_id'] = (string) $person->id;
    $source['actor_workos_user_id'] = $actor->workos_user_id;
    $source['payload'] = ['local_person_id' => $person->id, 'mapping_prerequisite' => true];
    $sourceHash = ApplyProductOperation::hash($source);
    AccountRequestOperation::create(['id' => $source['operation_id'], 'company_id' => $company->id, 'user_id' => $person->id, 'actor_id' => $actor->id, 'kind' => $source['kind'], 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => $source, 'payload_hash' => $sourceHash]);
    $mapping = $source;
    $mapping['operation_id'] = (string) Str::uuid();
    $mapping['kind'] = 'person.map-existing';
    $mapping['payload'] = ['local_person_id' => $person->id, 'workos_user_id' => $person->workos_user_id, 'invitation_id' => null, 'generation' => 0, 'source_operation_id' => $source['operation_id'], 'source_kind' => $source['kind'], 'source_payload_hash' => $sourceHash];
    $hash = ApplyProductOperation::hash($mapping);
    AccountRequestOperation::create(['id' => $mapping['operation_id'], 'company_id' => $company->id, 'user_id' => $person->id, 'actor_id' => $actor->id, 'kind' => $mapping['kind'], 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => $mapping, 'payload_hash' => $hash]);
    config(['services.account.actor_assertion_secret' => 'synthetic-live-source-proof']);
    $this->withToken($token)->postJson('/api/control-plane/v2/actor-authorizations', $mapping + ['payload_hash' => $hash])->assertOk()->assertJsonPath('actor_assertion.operation_id', $mapping['operation_id']);
    foreach (['hash', 'subject', 'source', 'actor', 'company'] as $field) {
        $bad = $mapping;
        match ($field) {
            'hash' => $bad['payload']['source_payload_hash'] = str_repeat('0', 64),'subject' => $bad['subject_id'] = 'foreign','source' => $bad['payload']['source_operation_id'] = (string) Str::uuid(),'actor' => $bad['actor_workos_user_id'] = 'user_unknown','company' => $bad['account_company_uuid'] = (string) Str::uuid()
        };
        $this->postJson('/api/control-plane/v2/actor-authorizations', $bad + ['payload_hash' => ApplyProductOperation::hash($bad)])->assertForbidden();
    }
    foreach (['subject', 'hash', 'actor', 'company', 'user', 'kind'] as $relation) {
        $stored = AccountRequestOperation::findOrFail($source['operation_id'])->replicate();
        $stored->id = (string) Str::uuid();
        $stored->forceFill(match ($relation) {
            'subject' => ['payload' => array_replace($source, ['subject_id' => 'foreign-subject'])],
            'hash' => ['payload_hash' => str_repeat('0', 64)],
            'actor' => ['actor_id' => User::factory()->create()->id],
            'company' => ['company_id' => Company::factory()->create()->id],
            'user' => ['user_id' => User::factory()->create()->id],
            'kind' => ['kind' => 'person.invite'],
        });
        $stored->save();
        $bound = $mapping;
        $bound['operation_id'] = (string) Str::uuid();
        $bound['payload']['source_operation_id'] = $stored->id;
        $boundHash = ApplyProductOperation::hash($bound);
        AccountRequestOperation::create(['id' => $bound['operation_id'], 'company_id' => $company->id, 'user_id' => $person->id, 'actor_id' => $actor->id, 'kind' => $bound['kind'], 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => $bound, 'payload_hash' => $boundHash]);
        expect(AccountRequestOperation::findOrFail($bound['operation_id'])->payload_hash)->toBe($boundHash);
        $before = AccountRequestOperation::orderBy('id')->get()->toJson();
        $this->postJson('/api/control-plane/v2/actor-authorizations', $bound + ['payload_hash' => $boundHash])->assertForbidden();
        expect(AccountRequestOperation::orderBy('id')->get()->toJson())->toBe($before);
    }
    $actor->roles()->detach();
    $this->postJson('/api/control-plane/v2/actor-authorizations', $mapping + ['payload_hash' => $hash])->assertForbidden();
    expect(AccountRequestOperation::count())->toBe(14);
    Http::assertNothingSent();
});

it('TA-01 validates fresh signed Account proof and rejects bad expired or reused proof without writes', function (string $failure) {
    [$company,$person,$principal,$token,$input] = Fixture::receiver();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
    $proof = array_intersect_key($input, array_flip(['operation_id', 'actor_workos_user_id', 'account_company_uuid', 'product', 'kind', 'subject_id'])) + ['payload_hash' => ApplyProductOperation::hash($input), 'issued_at' => now()->timestamp, 'expires_at' => now()->addMinute()->timestamp, 'nonce' => (string) Str::uuid()];
    $sign = function (array $p) {
        unset($p['signature']);
        ksort($p, SORT_STRING);
        $p['signature'] = hash_hmac('sha256', json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'synthetic-actor-proof');

        return $p;
    };
    $proof = $sign($proof);
    $current = $proof;
    Http::fake(['account.example.test/*' => fn () => Http::response(['actor_assertion' => $current])]);
    $client = app(AccountUserClient::class);
    expect($client->authorizeAccountOperation($input)['operation_id'])->toBe($input['operation_id']);
    if ($failure !== 'reused') {
        $current['nonce'] = (string) Str::uuid();
        match ($failure) {
            'expired' => $current['expires_at'] = now()->subSecond()->timestamp,'signature' => $current['signature'] = 'bad','hash' => $current['payload_hash'] = str_repeat('0', 64),'actor' => $current['actor_workos_user_id'] = 'user_forged','nonce' => $current['nonce'] = 'short'
        };
        if ($failure !== 'signature') {
            $current = $sign($current);
        }
    }
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['account.example.test/*' => fn () => Http::response(['actor_assertion' => $current])]);
    expect(fn () => $client->authorizeAccountOperation($input))->toThrow(HttpException::class);
    expect(ProductOperationReceipt::count())->toBe(0)->and(AccountRequestOperation::count())->toBe(0);
})->with(['expired', 'signature', 'hash', 'actor', 'nonce', 'reused']);
