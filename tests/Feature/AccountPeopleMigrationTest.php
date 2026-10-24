<?php

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Enums\UserStatus;
use App\Models\AccountCompanyBinding;
use App\Models\Company;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\ProductOperationReceipt;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AccountIntegrationFixture;

function migrationReceiver(): array
{
    [$company, $person, $principal] = AccountIntegrationFixture::receiver();
    $principal->update(['capabilities' => []]);
    $token = $principal->createToken('migration', [])->plainTextToken;
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
    $valid = $input;
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id']);
    $url = '/api/control-plane/migrations/v1/people/'.$input['operation_id'];
    $this->putJson($url, array_replace($input, ['name' => 'Changed']))->assertConflict();
    $input['workos_user_id'] = 'user_collision';
    $input['source_key'] = 'user:user_collision';
    unset($input['payload_hash']);
    $input['payload_hash'] = ApplyProductOperation::hash($input);
    $this->putJson($url, $input)->assertConflict();
    $input = $valid;
    $input['preview'] = true;
    unset($input['payload_hash']);
    $input['payload_hash'] = ApplyProductOperation::hash($input);
    $this->putJson($url, $input)->assertOk()->assertJsonPath('outcome', 'ready');
    expect(PersonAccountLink::count())->toBe(0)->and(ProductOperationReceipt::count())->toBe(0);
    DB::table('account_company_bindings')->where('compliance_company_id', $company->id)->update(['workos_organization_id' => 'org_revoked_binding']);
    $this->putJson($url, $input)->assertConflict();
    expect(PersonAccountLink::count())->toBe(0)->and(ProductOperationReceipt::count())->toBe(0);
    Http::assertNothingSent();
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

it('reuses an existing exact mapped local identity and rejects incompatible remapping without any evidence writes', function (bool $conflict) {
    [$company, $person, $principal, $token, $input] = migrationReceiver();
    $company->update(['compliance_access_enabled' => true]);
    $person->forceFill(['status' => UserStatus::Active, 'first_access_at' => now()->subYear(), 'last_access_at' => now()->subMonth(), 'workos_invitation_id' => 'invitation_retained'])->save();
    $assignment = UserTrainingAssignment::factory()->completed()->create(['user_id' => $person->id]);
    $role = Role::factory()->create();
    $person->roles()->attach($role->id);
    $link = PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => $input['account_company_uuid'], 'shared_person_id' => $input['shared_person_id'], 'company_member_id' => $input['company_member_id'], 'provenance' => ['existing' => 'preserved']]);
    $access = PersonProductAccess::create(['company_id' => $company->id, 'user_id' => $person->id, 'confirmed_enabled' => true, 'version' => 14, 'operation_id' => (string) Str::uuid()]);
    $before = [$person->fresh()->getRawOriginal(), $link->fresh()->getRawOriginal(), $access->fresh()->getRawOriginal(), $assignment->fresh()->getRawOriginal(), $person->roles()->orderBy('roles.id')->pluck('roles.id')->all()];
    if ($conflict) {
        $input['company_member_id'] = (string) Str::uuid();
    }
    unset($input['payload_hash']);
    $input['payload_hash'] = ApplyProductOperation::hash($input);
    $response = $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/migrations/v1/people/'.$input['operation_id'], $input);
    if ($conflict) {
        $response->assertConflict();
    } else {
        $response->assertSuccessful()->assertJsonPath('outcome', 'already_linked')->assertJsonPath('local_person_id', (string) $person->id)->assertJsonPath('observed_enabled', true);
    }
    expect([$person->fresh()->getRawOriginal(), $link->fresh()->getRawOriginal(), $access->fresh()->getRawOriginal(), $assignment->fresh()->getRawOriginal(), $person->roles()->orderBy('roles.id')->pluck('roles.id')->all()])->toBe($before)
        ->and(PersonAccountLink::count())->toBe(1)->and(ProductOperationReceipt::count())->toBe($conflict ? 0 : 1)->and(User::count())->toBe(1);
    Http::assertNothingSent();
})->with([false, true]);

it('rejects correctly rehashed changed-input replay without replacing an immutable receipt or mapping', function () {
    [$company, $person, $principal, $token, $input] = migrationReceiver();
    $url = '/api/control-plane/migrations/v1/people/'.$input['operation_id'];
    $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id']);
    $receipt = $this->putJson($url, $input)->assertSuccessful()->json();
    $before = [ProductOperationReceipt::sole()->getRawOriginal(), PersonAccountLink::sole()->getRawOriginal(), PersonProductAccess::sole()->getRawOriginal(), $person->fresh()->getRawOriginal()];
    $changed = $input;
    $changed['name'] = 'Correctly rehashed different input';
    unset($changed['payload_hash']);
    $changed['payload_hash'] = ApplyProductOperation::hash($changed);
    $this->putJson($url, $changed)->assertConflict();
    $this->putJson($url, $input)->assertExactJson($receipt);
    expect([ProductOperationReceipt::sole()->getRawOriginal(), PersonAccountLink::sole()->getRawOriginal(), PersonProductAccess::sole()->getRawOriginal(), $person->fresh()->getRawOriginal()])->toBe($before);
    Http::assertNothingSent();
});

it('interleaves an identical receiver request before the first response without duplicate receipt or projection overwrite', function () {
    [$company, $person, $principal, $token, $input] = migrationReceiver();
    $company->update(['compliance_access_enabled' => true]);
    $person->update(['status' => UserStatus::Active]);
    $nested = null;
    $interleaved = false;
    $url = '/api/control-plane/migrations/v1/people/'.$input['operation_id'];
    ProductOperationReceipt::created(function ($receipt) use (&$interleaved, &$nested, $input, $url, $person, $company, $token) {
        if ($interleaved || $receipt->operation_id !== $input['operation_id']) {
            return;
        }
        $interleaved = true;
        PersonProductAccess::where('company_id', $company->id)->where('user_id', $person->id)->update(['confirmed_enabled' => false, 'version' => 23]);
        $nested = $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson($url, $input)->assertSuccessful()->json();
    });
    $outer = $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson($url, $input)->assertSuccessful()->json();
    expect($interleaved)->toBeTrue()->and($nested)->toBe($outer)->and(ProductOperationReceipt::count())->toBe(1)->and(PersonAccountLink::count())->toBe(1)
        ->and(PersonProductAccess::count())->toBe(1)->and(User::count())->toBe(1)->and(PersonProductAccess::sole()->confirmed_enabled)->toBeFalse()->and(PersonProductAccess::sole()->version)->toBe(23);
    Http::assertNothingSent();
});

it('keeps the same WorkOS identity mapped to distinct existing local IDs in two actual tenant receivers', function () {
    [$a, $personA, $principal, $token, $inputA] = migrationReceiver();
    $b = Company::factory()->create(['workos_organization_id' => 'org_second_migration']);
    $uuidB = (string) Str::uuid();
    AccountCompanyBinding::create(['account_company_uuid' => $uuidB, 'compliance_company_id' => $b->id, 'workos_organization_id' => $b->workos_organization_id]);
    $principal->update(['company_uuids' => [...$principal->company_uuids, $uuidB]]);
    $context = app(TenantContext::class);
    $context->set($b);
    $personB = User::factory()->create(['workos_user_id' => $personA->workos_user_id, 'email' => $personA->email, 'status' => UserStatus::Terminated]);
    $context->set($a);
    $inputB = array_replace($inputA, ['operation_id' => (string) Str::uuid(), 'account_company_uuid' => $uuidB, 'workos_organization_id' => $b->workos_organization_id, 'company_member_id' => (string) Str::uuid(), 'workos_membership_id' => 'om_second']);
    unset($inputB['payload_hash']);
    $inputB['payload_hash'] = ApplyProductOperation::hash($inputB);
    $beforeA = $personA->fresh()->getRawOriginal();
    $context->set($b);
    $beforeB = $personB->fresh()->getRawOriginal();
    $context->set($a);
    foreach ([[$inputA, $personA], [$inputB, $personB]] as [$input, $local]) {
        $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])->putJson('/api/control-plane/migrations/v1/people/'.$input['operation_id'], $input)->assertSuccessful()->assertJsonPath('local_person_id', (string) $local->id);
    }
    expect($personA->id)->not->toBe($personB->id)->and(PersonAccountLink::where('company_id', $a->id)->sole()->shared_person_id)->toBe($inputA['shared_person_id'])
        ->and(PersonAccountLink::where('company_id', $b->id)->sole()->shared_person_id)->toBe($inputA['shared_person_id'])->and(PersonAccountLink::where('company_id', $a->id)->sole()->user_id)->toBe($personA->id)
        ->and(PersonAccountLink::where('company_id', $b->id)->sole()->user_id)->toBe($personB->id)->and($personA->fresh()->getRawOriginal())->toBe($beforeA);
    $context->set($b);
    expect($personB->fresh()->getRawOriginal())->toBe($beforeB)->and(PersonProductAccess::where('company_id', $b->id)->sole()->confirmed_enabled)->toBeFalse();
    $context->set($a);
    expect(ProductOperationReceipt::count())->toBe(2);
    Http::assertNothingSent();
});
