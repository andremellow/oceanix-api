<?php

use Illuminate\Support\Str;
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
