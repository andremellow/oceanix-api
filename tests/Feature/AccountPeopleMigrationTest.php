<?php

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Enums\UserStatus;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\ProductOperationReceipt;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AccountIntegrationFixture;

function migrationReceiver(): array
{
    [$company, $person, $principal] = AccountIntegrationFixture::receiver();
    $principal->update(['capabilities' => ['migration.people.link']]);
    $token = $principal->createToken('migration', ['migration.people.link'])->plainTextToken;
    $input = ['operation_id' => (string) Str::uuid(), 'preview' => false, 'account_company_uuid' => $principal->company_uuids[0], 'workos_organization_id' => $company->workos_organization_id, 'actor_workos_user_id' => 'user_synthetic_actor', 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid(), 'source_key' => 'user:'.$person->workos_user_id, 'workos_user_id' => $person->workos_user_id, 'workos_membership_id' => 'om_synthetic', 'invitation_id' => null, 'name' => $person->name, 'email' => $person->email, 'baseline_eligible' => true];
    $input['payload_hash'] = ApplyProductOperation::hash($input);

    return [$company, $person, $principal, $token, $input];
}

it('links an exact existing identity preserves all local evidence and replays the receipt', function () {
    [$company, $person, $principal, $token, $input] = migrationReceiver();
    $person->update(['status' => UserStatus::Suspended]);
    $person->forceFill(['first_access_at' => now()->subYear(), 'last_access_at' => now()->subMonth()])->save();
    $assignment = UserTrainingAssignment::factory()->completed()->create(['user_id' => $person->id]);
    $history = $assignment->fresh()->getAttributes();
    $role = Role::factory()->create();
    $person->roles()->attach($role->id);
    $roles = $person->roles()->pluck('roles.id')->all();
    $before = $person->fresh()->getAttributes();
    PersonProductAccess::create(['company_id' => $company->id, 'user_id' => $person->id, 'confirmed_enabled' => false, 'version' => 8, 'operation_id' => (string) Str::uuid()]);
    $url = '/api/control-plane/migrations/v1/people/'.$input['operation_id'];
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id']);
    $response = $this->putJson($url, $input)->assertSuccessful()->assertJsonPath('local_person_id', (string) $person->id)->assertJsonPath('observed_enabled', false)->json();
    $this->putJson($url, $input)->assertExactJson($response);
    expect($person->fresh()->getAttributes())->toBe($before)->and(PersonAccountLink::count())->toBe(1)->and(ProductOperationReceipt::count())->toBe(1)->and(PersonProductAccess::sole()->version)->toBe(8);
    expect($assignment->fresh()->getAttributes())->toBe($history)->and($person->roles()->pluck('roles.id')->all())->toBe($roles);
    Http::assertNothingSent();
});

it('previews without writes and creates only invited genuine absences without email matching', function () {
    [$company, $person, $principal, $token, $input] = migrationReceiver();
    $input['workos_user_id'] = 'user_new_migration';
    $input['source_key'] = 'user:user_new_migration';
    $input['email'] = 'new-migration@example.test';
    $input['preview'] = true;
    unset($input['payload_hash']);
    $input['payload_hash'] = ApplyProductOperation::hash($input);
    $url = '/api/control-plane/migrations/v1/people/'.$input['operation_id'];
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson($url, $input)->assertSuccessful()->assertJsonPath('outcome', 'ready');
    expect(PersonAccountLink::count())->toBe(0)->and(ProductOperationReceipt::count())->toBe(0)->and(User::count())->toBe(1);
    $input['preview'] = false;
    unset($input['payload_hash']);
    $input['payload_hash'] = ApplyProductOperation::hash($input);
    $this->putJson($url, $input)->assertSuccessful()->assertJsonPath('observed_enabled', false);
    $new = User::where('workos_user_id', 'user_new_migration')->sole();
    expect($new->status)->toBe(UserStatus::Invited)->and($new->first_access_at)->toBeNull()->and($new->roles()->where('key', '!=', 'employee')->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('rejects tenant capability hash and email-only collisions with no effects', function () {
    [$company, $person, $principal, $token, $input] = migrationReceiver();
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id']);
    $url = '/api/control-plane/migrations/v1/people/'.$input['operation_id'];
    $this->putJson($url, array_replace($input, ['name' => 'Changed']))->assertConflict();
    $input['workos_user_id'] = 'user_collision';
    $input['source_key'] = 'user:user_collision';
    unset($input['payload_hash']);
    $input['payload_hash'] = ApplyProductOperation::hash($input);
    $this->putJson($url, $input)->assertConflict();
    $principal->update(['company_uuids' => []]);
    $this->putJson($url, $input)->assertForbidden();
    expect(PersonAccountLink::count())->toBe(0)->and(ProductOperationReceipt::count())->toBe(0);
});

it('links exact invitation-only identities and creates no replacement or role changes', function () {
    [$company,$person,$principal,$token,$input] = migrationReceiver();
    $person->forceFill(['workos_user_id' => null, 'workos_invitation_id' => 'invitation_exact', 'status' => UserStatus::Invited])->save();
    $input['workos_user_id'] = null;
    $input['workos_membership_id'] = null;
    $input['invitation_id'] = 'invitation_exact';
    $input['source_key'] = 'invitation:invitation_exact';
    $input['baseline_eligible'] = false;
    unset($input['payload_hash']);
    $input['payload_hash'] = ApplyProductOperation::hash($input);
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/migrations/v1/people/'.$input['operation_id'], $input)->assertSuccessful()->assertJsonPath('local_person_id', (string) $person->id)->assertJsonPath('observed_enabled', false);
    expect(User::count())->toBe(1)->and($person->fresh()->workos_user_id)->toBeNull()->and($person->fresh()->first_access_at)->toBeNull();
});

it('denies expired inactive wrong-environment principals and altered operation bindings', function () {
    [$company,$person,$principal,$token,$input] = migrationReceiver();
    $url = '/api/control-plane/migrations/v1/people/'.$input['operation_id'];
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id']);
    $principal->update(['expires_at' => now()->subSecond()]);
    $this->putJson($url, $input)->assertForbidden();
    $principal->update(['expires_at' => null, 'active' => false]);
    $this->putJson($url, $input)->assertForbidden();
    $principal->update(['active' => true, 'environment' => 'production']);
    $this->putJson($url, $input)->assertForbidden();
    $principal->update(['environment' => 'testing']);
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson($url, $input)->assertForbidden();
    expect(PersonAccountLink::count())->toBe(0)->and(ProductOperationReceipt::count())->toBe(0);
});
