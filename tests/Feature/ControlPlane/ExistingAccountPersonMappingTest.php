<?php

use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountIntegrationFixture as Fixture;

it('SC-09 SC-11 maps an existing exact company identity without changing people or access', function () {
    Fixture::fakeAccountActorAuthorization();
    [$company, $person, $principal, $token, $input] = Fixture::receiver();
    $before = DB::table('users')->orderBy('id')->get()->toJson();
    $access = DB::table((new PersonProductAccess)->getTable())->get()->toJson();
    $input['kind'] = 'person.map-existing';
    $input['payload'] = ['shared_person_id' => (string) Str::uuid(), 'workos_user_id' => $person->workos_user_id];
    $url = '/api/control-plane/v2/operations/'.$input['operation_id'];
    $first = $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson($url, $input)->assertSuccessful()->assertJsonPath('confirmed_state.mapping_complete', true)->assertJsonPath('confirmed_state.local_person_id', (string) $person->id)->json();
    $this->putJson($url, $input)->assertExactJson($first);
    expect(DB::table('users')->orderBy('id')->get()->toJson())->toBe($before)->and(DB::table((new PersonProductAccess)->getTable())->get()->toJson())->toBe($access)->and(PersonAccountLink::count())->toBe(1);
    $input['operation_id'] = (string) Str::uuid();
    $input['payload']['shared_person_id'] = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertConflict();
    expect(PersonAccountLink::count())->toBe(1)->and(DB::table('users')->orderBy('id')->get()->toJson())->toBe($before);
});
