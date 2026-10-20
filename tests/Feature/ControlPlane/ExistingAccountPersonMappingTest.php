<?php

use App\Models\AccountCompanyBinding;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\ProductOperationReceipt;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTrainingAssignment;
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
    $changed = $input;
    $changed['payload']['shared_person_id'] = (string) Str::uuid();
    $this->putJson($url, $changed)->assertConflict();
    $this->putJson($url, $input)->assertExactJson($first);
    expect(DB::table('users')->orderBy('id')->get()->toJson())->toBe($before)->and(DB::table((new PersonProductAccess)->getTable())->get()->toJson())->toBe($access)->and(PersonAccountLink::count())->toBe(1);
    $input['operation_id'] = (string) Str::uuid();
    $input['payload']['shared_person_id'] = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertConflict();
    expect(PersonAccountLink::count())->toBe(1)->and(DB::table('users')->orderBy('id')->get()->toJson())->toBe($before);
});

it('TA-02 maps invitation-only people then projects expired and revoked evidence without changing access', function (string $state) {
    Fixture::fakeAccountActorAuthorization();
    [$company,$person,$principal,$token,$input] = Fixture::receiver();
    $person->forceFill(['workos_user_id' => null, 'workos_invitation_id' => 'invitation_only_exact', 'invitation_generation' => 1])->save();
    $before = $person->fresh()->getAttributes();
    $roles = DB::table('role_user')->get()->toJson();
    $access = PersonProductAccess::all()->toJson();
    $input['kind'] = 'person.map-existing';
    $input['payload'] = ['shared_person_id' => (string) Str::uuid(), 'workos_user_id' => null, 'invitation_id' => 'invitation_only_exact'];
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertSuccessful()->assertJsonPath('confirmed_state.local_person_id', (string) $person->id);
    expect($person->fresh()->getAttributes())->toBe($before);
    $link = PersonAccountLink::sole();
    $input['operation_id'] = (string) Str::uuid();
    $input['kind'] = 'person.invitation.observed';
    $input['payload'] = ['local_person_id' => $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id, 'workos_user_id' => null, 'generation' => 1, 'observation_sequence' => 1, 'verified_at' => now()->toIso8601String(), 'accepted_history_at' => null, 'invitation' => ['id' => 'invitation_only_exact', 'state' => $state]];
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertSuccessful();
    expect($person->fresh()->invitation_state->value)->toBe($state)->and($person->fresh()->status->value)->toBe($before['status'])->and(DB::table('role_user')->get()->toJson())->toBe($roles)->and(PersonProductAccess::all()->toJson())->toBe($access)->and(PersonAccountLink::count())->toBe(1);
})->with(['expired', 'revoked']);

it('TA-02 rejects exact-mapping conflicts while a valid peer independently finishes', function (string $failure) {
    Fixture::fakeAccountActorAuthorization();
    [$company,$person,$principal,$token,$input] = Fixture::receiver();
    $person->forceFill(['workos_user_id' => null, 'workos_invitation_id' => 'invitation_conflict'])->save();
    $peer = User::factory()->create(['workos_user_id' => 'user_valid_peer']);
    $input['kind'] = 'person.map-existing';
    $input['payload'] = ['shared_person_id' => (string) Str::uuid(), 'workos_user_id' => null, 'invitation_id' => 'invitation_conflict'];
    if ($failure === 'duplicate') {
        User::factory()->create(['workos_user_id' => null, 'workos_invitation_id' => 'invitation_conflict']);
    }
    if ($failure === 'organization') {
        $input['workos_organization_id'] = 'org_foreign';
    }
    if ($failure === 'binding') {
        $input['account_company_uuid'] = (string) Str::uuid();
    }
    if ($failure === 'different-stable-id') {
        $input['payload']['workos_user_id'] = 'user_email_collision';
        $input['payload']['invitation_id'] = null;
    }
    if ($failure === 'occupied-link') {
        PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => $input['account_company_uuid'], 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid(), 'provenance' => []]);
    }
    if ($failure === 'local-id') {
        $input['payload']['local_person_id'] = $peer->id;
    }
    $users = DB::table('users')->orderBy('id')->get()->toJson();
    $links = PersonAccountLink::all()->toJson();
    $access = PersonProductAccess::all()->toJson();
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertConflict();
    expect(DB::table('users')->orderBy('id')->get()->toJson())->toBe($users)->and(PersonAccountLink::all()->toJson())->toBe($links)->and(PersonProductAccess::all()->toJson())->toBe($access)->and(ProductOperationReceipt::count())->toBe(0);
    $binding = AccountCompanyBinding::where('compliance_company_id', $company->id)->sole();
    $input['account_company_uuid'] = $binding->account_company_uuid;
    $input['workos_organization_id'] = $company->workos_organization_id;
    $input['operation_id'] = (string) Str::uuid();
    $input['subject_id'] = (string) Str::uuid();
    $input['payload'] = ['shared_person_id' => (string) Str::uuid(), 'workos_user_id' => $peer->workos_user_id];
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertSuccessful()->assertJsonPath('confirmed_state.local_person_id', (string) $peer->id);
    expect(DB::table('users')->orderBy('id')->get()->toJson())->toBe($users)->and(PersonProductAccess::all()->toJson())->toBe($access)->and(ProductOperationReceipt::count())->toBe(1);
})->with(['duplicate', 'organization', 'binding', 'different-stable-id', 'occupied-link', 'local-id']);

it('QA-R02 initializes only untouched exact identity mapping without claiming verification', function () {
    Fixture::fakeAccountActorAuthorization();
    [$company, $person, $principal, $token, $input] = Fixture::receiver();
    $person->forceFill(['workos_invitation_id' => null, 'invitation_generation' => 0, 'invitation_observation_sequence' => 0, 'invitation_observation_hash' => null, 'invitation_verified_at' => null])->save();
    $input['kind'] = 'person.map-existing';
    $input['payload'] = ['shared_person_id' => (string) Str::uuid(), 'workos_user_id' => $person->workos_user_id, 'invitation_id' => 'invitation_imported'];
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertSuccessful();
    expect($person->fresh()->workos_invitation_id)->toBe('invitation_imported')->and($person->fresh()->invitation_verified_at)->toBeNull()->and($person->fresh()->invitation_observation_sequence)->toBe(0);
});

it('TA-R01 rejects initialized null invitation mapping independently of each untouched guard', function (string $guard) {
    Fixture::fakeAccountActorAuthorization();
    [$company, $person, $principal, $token, $input] = Fixture::receiver();
    $person->forceFill(['workos_invitation_id' => null, 'invitation_generation' => 0, 'invitation_observation_sequence' => 0, 'invitation_observation_hash' => null, 'invitation_verified_at' => null])->save();
    $input['kind'] = 'person.map-existing';
    $input['payload'] = ['shared_person_id' => (string) Str::uuid(), 'workos_user_id' => $person->workos_user_id];
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertSuccessful();
    $person->forceFill(['invitation_observation_sequence' => in_array($guard, ['sequence', 'observed'], true) ? 1 : 0, 'invitation_observation_hash' => in_array($guard, ['hash', 'observed'], true) ? str_repeat('c', 64) : null, 'invitation_verified_at' => in_array($guard, ['verified', 'observed'], true) ? now()->subDay() : null])->save();
    seedAccessCatalog();
    $person->roles()->syncWithoutDetaching(Role::where('key', 'employee')->sole()->id);
    PersonProductAccess::create(['company_id' => $company->id, 'user_id' => $person->id, 'confirmed_enabled' => true, 'version' => 7, 'operation_id' => (string) Str::uuid()]);
    $course = Course::factory()->create();
    $assignment = UserTrainingAssignment::factory()->forCourse($course)->completed()->create(['user_id' => $person->id]);
    Certificate::factory()->create(['user_id' => $person->id, 'assignment_id' => $assignment->id, 'course_id' => $assignment->course_id, 'course_version_id' => $assignment->course_version_id]);
    $tables = ['users', 'person_account_links', 'product_operation_receipts', 'person_product_accesses', 'role_user', 'user_training_assignments', 'certificates'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $input['operation_id'] = (string) Str::uuid();
    $input['payload']['invitation_id'] = 'invitation_forbidden_replacement';
    $this->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/v2/operations/'.$input['operation_id'], $input)->assertConflict();
    expect(collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all())->toBe($before);
})->with(['sequence', 'hash', 'verified', 'observed']);
