<?php

use App\Models\PersonAccountLink;
use App\Models\ProductOperationReceipt;
use Illuminate\Support\Str;
use Tests\Support\AccountIntegrationFixture as Fixture;

it('SC-10 SC-16 applies invitation-only snapshots atomically and preserves access and accepted history', function () {
    Fixture::fakeAccountActorAuthorization();
    [$company, $person, $principal, $token, $input] = Fixture::receiver();
    $link = PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => $input['account_company_uuid'], 'shared_person_id' => $input['payload']['shared_person_id'], 'company_member_id' => $input['subject_id'], 'provenance' => ['fixture' => true]]);
    $person->forceFill(['invitation_generation' => 1, 'workos_invitation_id' => 'invitation_existing', 'invitation_accepted_history_at' => '2026-10-01T10:00:00Z'])->save();
    $before = $person->fresh()->getAttributes();
    $input['kind'] = 'person.invitation.observed';
    $input['payload'] = ['local_person_id' => $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id, 'workos_user_id' => $person->workos_user_id, 'generation' => 1, 'observation_sequence' => 2, 'verified_at' => '2026-10-20T12:00:00Z', 'accepted_history_at' => null, 'invitation' => ['id' => 'invitation_existing', 'state' => 'expired', 'created_at' => '2026-10-01T00:00:00Z']];
    $url = '/api/control-plane/v2/operations/'.$input['operation_id'];
    $receipt = $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson($url, $input)->assertSuccessful()->assertJsonPath('confirmed_state.result', 'applied')->json();
    $this->putJson($url, $input)->assertExactJson($receipt);
    $after = $person->fresh()->getAttributes();
    foreach (['status', 'workos_active_membership', 'invitation_accepted_history_at'] as $field) {
        expect($after[$field])->toBe($before[$field]);
    }
    $input['operation_id'] = (string) Str::uuid();
    $input['payload']['observation_sequence'] = 1;
    $input['payload']['invitation']['state'] = 'pending';
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertSuccessful()->assertJsonPath('confirmed_state.result', 'superseded');
    expect($person->fresh()->getAttributes())->toBe($after);
});

it('SC-16 rolls back the projection if the atomic receipt cannot be persisted', function () {
    Fixture::fakeAccountActorAuthorization();
    [$company,$person,$principal,$token,$input] = Fixture::receiver();
    $link = PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => $input['account_company_uuid'], 'shared_person_id' => $input['payload']['shared_person_id'], 'company_member_id' => $input['subject_id'], 'provenance' => []]);
    $person->forceFill(['invitation_generation' => 1, 'workos_invitation_id' => 'invitation_atomic'])->save();
    $input['kind'] = 'person.invitation.observed';
    $input['payload'] = ['local_person_id' => $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id, 'workos_user_id' => $person->workos_user_id, 'generation' => 1, 'observation_sequence' => 1, 'verified_at' => '2026-10-20T00:00:00Z', 'accepted_history_at' => null, 'invitation' => ['id' => 'invitation_atomic', 'state' => 'expired', 'created_at' => '2026-10-01T00:00:00Z']];
    $before = $person->fresh()->getAttributes();
    ProductOperationReceipt::creating(fn () => throw new RuntimeException('Synthetic receipt persistence failure'));
    try {
        $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertStatus(500);
        expect($person->fresh()->getAttributes())->toBe($before)->and(ProductOperationReceipt::count())->toBe(0);
    } finally {
        ProductOperationReceipt::flushEventListeners();
    }
});
