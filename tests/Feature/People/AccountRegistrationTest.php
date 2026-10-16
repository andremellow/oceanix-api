<?php

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Actions\People\RegisterPerson;
use App\Enums\Permission;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
use App\Models\ComplianceEvent;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function accountRegistrationActor(): User
{
    seedAccessCatalog();
    Http::preventStrayRequests();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic-account-token', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_registration']);
    AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => $company->workos_organization_id]);
    $actor = userWithPermissions([Permission::PeopleManage]);
    $actor->forceFill(['workos_user_id' => 'user_registration_actor'])->save();

    return $actor;
}

function confirmedRegistrationReceipt(array $envelope): array
{
    return array_intersect_key($envelope, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => [
        'shared_person_id' => '11111111-1111-4111-8111-111111111111', 'company_member_id' => '22222222-2222-4222-8222-222222222222', 'workos_user_id' => 'user_registered', 'workos_membership_id' => 'om_registered', 'membership_status' => 'pending',
        'registration_source' => $envelope['payload']['registration_source'], 'actor_workos_user_id' => $envelope['actor_workos_user_id'], 'request_payload_hash' => ApplyProductOperation::hash($envelope), 'registration_mode' => 'confirmed-first', 'mapping_complete' => false,
    ]];
}

it('creates no provisional person and enables only after exact mapping acknowledgement', function () {
    $actor = accountRegistrationActor();
    Http::fake(['account.example.test/*' => function ($request) {
        $envelope = $request->data();
        if (str_ends_with($request->url(), '/local-person')) {
            $person = User::where('email', 'new@example.test')->sole();
            expect($person->roles()->count())->toBe(0)->and(PersonProductAccess::where('user_id', $person->id)->sole()->confirmed_enabled)->toBeFalse();

            return Http::response(array_intersect_key($envelope, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence', 'actor_workos_user_id'])) + ['outcome' => 'confirmed', 'confirmed_state' => $envelope['payload'] + ['mapping_complete' => true]]);
        }
        expect(User::where('email', 'new@example.test')->count())->toBe(0)->and($envelope['subject_id'])->toBe($envelope['operation_id']);

        return Http::response(confirmedRegistrationReceipt($envelope));
    }]);
    $id = (string) Str::uuid();
    $operation = app(RegisterPerson::class)->handle($actor, $id, 'New person', 'new@example.test');
    expect($operation->state)->toBe('confirmed')->and($operation->user_id)->not->toBeNull();
    expect(User::where('email', 'new@example.test')->sole()->workos_user_id)->toBe('user_registered');
    expect(PersonProductAccess::where('user_id', $operation->user_id)->sole()->confirmed_enabled)->toBeTrue();
    app(RegisterPerson::class)->handle($actor, $id, 'New person', 'new@example.test');
    Http::assertSentCount(2);
});

it('persists only the original request when confirmation is unavailable', function () {
    $actor = accountRegistrationActor();
    Http::fake(['account.example.test/*' => Http::failedConnection()]);
    $operation = app(RegisterPerson::class)->handle($actor, (string) Str::uuid(), 'Pending', 'pending@example.test');
    expect($operation->state)->toBe('unknown')->and($operation->user_id)->toBeNull()->and(User::where('email', 'pending@example.test')->count())->toBe(0);
    app(RegisterPerson::class)->handle($actor, $operation->id, 'Pending', 'pending@example.test');
    expect(AccountRequestOperation::count())->toBe(1)->and(User::where('email', 'pending@example.test')->count())->toBe(0);
});

it('does not materialize invalid or non200 receipts', function ($status, $mutation) {
    $actor = accountRegistrationActor();
    Http::fake(['account.example.test/*' => function ($request) use ($status, $mutation) {
        $receipt = confirmedRegistrationReceipt($request->data());
        $receipt = $mutation($receipt);

        return Http::response($receipt, $status);
    }]);
    $operation = app(RegisterPerson::class)->handle($actor, (string) Str::uuid(), 'Pending', 'pending@example.test');
    expect($operation->state)->not->toBe('confirmed')->and($operation->user_id)->toBeNull()->and(User::where('email', 'pending@example.test')->count())->toBe(0);
})->with([
    '201' => [201, fn ($r) => $r], '202' => [202, fn ($r) => $r], 'pending' => [200, fn ($r) => array_replace($r, ['outcome' => 'pending'])],
    'empty identity' => [200, fn ($r) => array_replace($r, ['confirmed_state' => []])],
    'foreign hash' => [200, fn ($r) => array_replace_recursive($r, ['confirmed_state' => ['request_payload_hash' => str_repeat('0', 64)]])],
    'foreign actor' => [200, fn ($r) => array_replace_recursive($r, ['confirmed_state' => ['actor_workos_user_id' => 'user_other']])],
    'foreign source' => [200, fn ($r) => array_replace_recursive($r, ['confirmed_state' => ['registration_source' => 'import']])],
]);

it('recovers original main response loss and mapping response loss without duplicate materialization', function () {
    $actor = accountRegistrationActor();
    $main = null;
    $mappingCalls = 0;
    Http::fake(['account.example.test/*' => function ($request) use (&$main, &$mappingCalls) {
        if (str_ends_with($request->url(), '/local-person')) {
            $mappingCalls++;
            if ($mappingCalls === 1) {
                return Http::failedConnection();
            }
            $data = $request->data();

            return Http::response(array_intersect_key($data, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence', 'actor_workos_user_id'])) + ['outcome' => 'confirmed', 'confirmed_state' => $data['payload'] + ['mapping_complete' => true]]);
        }
        if ($request->method() === 'PUT') {
            $main = $request->data();

            return Http::failedConnection();
        }

        return Http::response(confirmedRegistrationReceipt($main));
    }]);
    $id = (string) Str::uuid();
    $first = app(RegisterPerson::class)->handle($actor, $id, 'Recovery', 'recovery@example.test');
    expect($first->user_id)->toBeNull();
    $second = app(RegisterPerson::class)->handle($actor, $id, 'Recovery', 'recovery@example.test');
    expect($second->state)->toBe('mapping_pending')->and($second->user_id)->not->toBeNull();
    $person = User::findOrFail($second->user_id);
    expect($person->roles()->count())->toBe(0)->and(PersonProductAccess::where('user_id', $person->id)->sole()->confirmed_enabled)->toBeFalse();
    $third = app(RegisterPerson::class)->handle($actor, $id, 'Recovery', 'recovery@example.test');
    expect($third->state)->toBe('confirmed')->and($third->user_id)->toBe($person->id)->and(User::where('email', $person->email)->count())->toBe(1);
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT' && ! str_ends_with($pair[0]->url(), '/local-person'))->count())->toBe(1);
    expect($third->payload_hash)->toBe($first->payload_hash);
    expect(fn () => $third->update(['user_id' => $actor->id]))->toThrow(LogicException::class);
});

it('rechecks current permissions before materialization and effective grant', function ($stage) {
    $actor = accountRegistrationActor();
    Http::fake(['account.example.test/*' => function ($request) use ($actor, $stage) {
        $data = $request->data();
        $mapping = str_ends_with($request->url(), '/local-person');
        if (($stage === 'materialize' && ! $mapping) || ($stage === 'grant' && $mapping)) {
            $actor->roles()->detach();
        }
        if ($mapping) {
            return Http::response(array_intersect_key($data, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence', 'actor_workos_user_id'])) + ['outcome' => 'confirmed', 'confirmed_state' => $data['payload'] + ['mapping_complete' => true]]);
        }

        return Http::response(confirmedRegistrationReceipt($data));
    }]);
    $operation = app(RegisterPerson::class)->handle($actor, (string) Str::uuid(), 'Revoked', 'revoked@example.test');
    expect($operation->state)->not->toBe('confirmed');
    if ($stage === 'materialize') {
        expect($operation->user_id)->toBeNull();
    } else {
        expect(User::findOrFail($operation->user_id)->roles()->count())->toBe(0)->and(PersonProductAccess::where('user_id', $operation->user_id)->sole()->confirmed_enabled)->toBeFalse();
    }
})->with(['materialize', 'grant']);

it('reuses pending email reservation and rejects another actor or changed source', function () {
    $actor = accountRegistrationActor();
    Http::fake(['account.example.test/*' => Http::failedConnection()]);
    $first = app(RegisterPerson::class)->handle($actor, (string) Str::uuid(), 'Reserved', 'reserved@example.test');
    $retry = app(RegisterPerson::class)->handle($actor, (string) Str::uuid(), 'Reserved', 'reserved@example.test');
    expect($retry->id)->toBe($first->id)->and(AccountRequestOperation::count())->toBe(1)->and(User::where('email', 'reserved@example.test')->count())->toBe(0);
    $other = userWithPermissions([Permission::PeopleManage]);
    $other->update(['workos_user_id' => 'user_other_actor']);
    expect(fn () => app(RegisterPerson::class)->handle($other, (string) Str::uuid(), 'Reserved', 'reserved@example.test'))->toThrow(HttpException::class);
});

it('preserves legacy provisional users and immutable envelopes while recovering their callback protocol', function () {
    $actor = accountRegistrationActor();
    $person = User::factory()->create(['email' => 'legacy@example.test']);
    $binding = AccountCompanyBinding::sole();
    $id = (string) Str::uuid();
    $payload = ['protocol_version' => 2, 'operation_id' => $id, 'kind' => 'person.register', 'product' => 'compliance', 'account_company_uuid' => $binding->account_company_uuid, 'workos_organization_id' => $binding->workos_organization_id, 'subject_id' => (string) $person->id, 'resource_sequence' => 1, 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => ['name' => $person->name, 'email' => $person->email, 'local_person_id' => $person->id, 'registration_source' => 'form']];
    $operation = AccountRequestOperation::create(['id' => $id, 'company_id' => $actor->company_id, 'user_id' => $person->id, 'actor_id' => $actor->id, 'actor_workos_user_id' => $actor->workos_user_id, 'kind' => 'person.register', 'payload' => $payload, 'payload_hash' => ApplyProductOperation::hash($payload), 'dispatched_at' => now(), 'state' => 'unknown']);
    PersonProductAccess::create(['company_id' => $actor->company_id, 'user_id' => $person->id, 'confirmed_enabled' => false, 'version' => 0, 'operation_id' => $id]);
    $before = $person->fresh()->getRawOriginal();
    Http::fake(['account.example.test/*' => function () use ($payload, $person) {
        PersonProductAccess::where('user_id', $person->id)->update(['confirmed_enabled' => true, 'version' => 1]);

        return Http::response(array_intersect_key($payload, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['enabled' => true]]);
    }]);
    $result = app(RegisterPerson::class)->handle($actor, $id, $person->name, $person->email);
    expect($result->state)->toBe('confirmed')->and($result->payload)->toBe($payload)->and($result->user_id)->toBe($person->id)->and($person->fresh()->getRawOriginal())->toBe($before);
    app(RegisterPerson::class)->handle($actor, $id, $person->name, $person->email);
    Http::assertSentCount(1);
});

it('retains disabled materialization and original evidence after an invalid mapping acknowledgement', function ($fault) {
    $actor = accountRegistrationActor();
    $main = null;
    $recover = false;
    Http::fake(['account.example.test/*' => function ($request) use (&$main, &$recover, $fault) {
        $data = $request->data();
        if (! str_ends_with($request->url(), '/local-person')) {
            if ($request->method() === 'PUT') {
                $main = $data;
            }

return Http::response(confirmedRegistrationReceipt($main));
        }
        $receipt = array_intersect_key($data, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence', 'actor_workos_user_id'])) + ['outcome' => 'confirmed', 'confirmed_state' => $data['payload'] + ['mapping_complete' => true]];
        if (! $recover) {
            match ($fault) {
                'hash' => $receipt['confirmed_state']['request_payload_hash'] = str_repeat('0', 64),'identity' => $receipt['confirmed_state']['workos_user_id'] = 'user_foreign','localid' => $receipt['confirmed_state']['local_person_id'] = $data['payload']['local_person_id'] + 1,'incomplete' => $receipt['confirmed_state']['mapping_complete'] = false,'empty' => $receipt['confirmed_state'] = [],default => null
            };
        }

        return Http::response($receipt, ! $recover && $fault === 'non200' ? 202 : 200);
    }]);
    $id = (string) Str::uuid();
    $first = app(RegisterPerson::class)->handle($actor, $id, 'Ack boundary', 'ack@example.test');
    expect($first->state)->toBe('mapping_pending')->and($first->user_id)->not->toBeNull();
    $person = User::findOrFail($first->user_id);
    $before = $person->getRawOriginal();
    $registration = $first->receipt['registration'];
    $link = PersonAccountLink::where('user_id', $person->id)->sole()->getRawOriginal();
    expect($person->roles()->count())->toBe(0)->and(PersonProductAccess::where('user_id', $person->id)->sole()->confirmed_enabled)->toBeFalse();
    $recover = true;
    $result = app(RegisterPerson::class)->handle($actor, $id, 'Ack boundary', 'ack@example.test');
    expect($result->state)->toBe('confirmed')->and($result->user_id)->toBe($first->user_id)->and($result->payload_hash)->toBe($first->payload_hash)->and($result->receipt['registration'])->toBe($registration);
    expect($person->fresh()->getRawOriginal())->toBe($before)->and(PersonAccountLink::where('user_id', $person->id)->sole()->getRawOriginal())->toBe($link)->and(User::where('email', $person->email)->count())->toBe(1);
})->with(['hash', 'identity', 'localid', 'incomplete', 'empty', 'non200']);

it('does not downgrade a concurrently completed registration after a late response', function ($late) {
    $actor = accountRegistrationActor();
    $id = (string) Str::uuid();
    $main = null;
    $nested = null;
    Http::fake(['account.example.test/*' => function ($request) use ($actor, $id, $late, &$main, &$nested) {
        $data = $request->data();
        if (str_ends_with($request->url(), '/local-person')) {
            return Http::response(array_intersect_key($data, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence', 'actor_workos_user_id'])) + ['outcome' => 'confirmed', 'confirmed_state' => $data['payload'] + ['mapping_complete' => true]]);
        }
        if ($request->method() === 'GET') {
            return Http::response(confirmedRegistrationReceipt($main));
        }
        $main = $data;
        $nested = app(RegisterPerson::class)->handle($actor, $id, 'Interleaved', 'interleaved@example.test');
        expect($nested->state)->toBe('confirmed');
        if ($late === 'failure') {
            return Http::failedConnection();
        }

        return Http::response(array_replace(confirmedRegistrationReceipt($main), ['outcome' => 'pending']));
    }]);
    $result = app(RegisterPerson::class)->handle($actor, $id, 'Interleaved', 'interleaved@example.test');
    expect($result->state)->toBe('confirmed')->and($result->user_id)->toBe($nested->user_id)->and($result->payload_hash)->toBe($nested->payload_hash)->and($result->receipt)->toBe($nested->receipt);
    expect(User::where('email', 'interleaved@example.test')->count())->toBe(1)->and(PersonAccountLink::where('user_id', $result->user_id)->count())->toBe(1)->and(PersonProductAccess::where('user_id', $result->user_id)->sole()->confirmed_enabled)->toBeTrue();
    Http::assertSentCount(3);
})->with(['failure', 'pending']);

it('never merges a confirmed receipt into an existing identity or racing email', function ($collision) {
    $actor = accountRegistrationActor();
    $existing = User::factory()->suspended()->create(['email' => 'preserved@example.test', 'workos_user_id' => $collision === 'identity' ? 'user_registered' : 'user_preserved']);
    $existing->roles()->attach(Role::where('key', 'employee')->sole());
    $before = $existing->fresh()->getRawOriginal();
    $roles = $existing->roles()->pluck('roles.id')->all();
    $events = ComplianceEvent::count();
    $assignments = UserTrainingAssignment::count();
    $main = null;
    Http::fake(['account.example.test/*' => function ($request) use ($collision, &$main) {
        $main = $request->data();
        if ($collision === 'email') {
            User::factory()->suspended()->create(['email' => 'collision@example.test', 'workos_user_id' => 'user_racing']);
        }

        return Http::response(confirmedRegistrationReceipt($main));
    }]);
    $result = app(RegisterPerson::class)->handle($actor, (string) Str::uuid(), 'Collision', 'collision@example.test');
    expect($result->state)->not->toBe('confirmed')->and($result->user_id)->toBeNull()->and($result->payload_hash)->toBe(ApplyProductOperation::hash($result->payload));
    expect($existing->fresh()->getRawOriginal())->toBe($before)->and($existing->roles()->pluck('roles.id')->all())->toBe($roles)->and(ComplianceEvent::count())->toBe($events)->and(UserTrainingAssignment::count())->toBe($assignments);
    expect(PersonAccountLink::count())->toBe(0)->and(PersonProductAccess::count())->toBe(0)->and(User::where('email', 'collision@example.test')->count())->toBe($collision === 'email' ? 1 : 0);
    Http::assertSentCount(1);
})->with(['identity', 'email']);

it('rolls back every local materialization row when a later insert violates a real constraint', function () {
    $actor = accountRegistrationActor();
    $existing = User::factory()->create(['email' => 'untouched@example.test']);
    $before = $existing->fresh()->getRawOriginal();
    // A real immutable link uniqueness constraint fails only after the new User insert.
    PersonAccountLink::create(['company_id' => $actor->company_id, 'user_id' => $existing->id, 'account_company_uuid' => AccountCompanyBinding::sole()->account_company_uuid, 'shared_person_id' => '11111111-1111-4111-8111-111111111111', 'company_member_id' => '22222222-2222-4222-8222-222222222222', 'provenance' => ['existing' => true]]);
    $link = PersonAccountLink::sole()->getRawOriginal();
    Http::fake(['account.example.test/*' => fn ($request) => Http::response(confirmedRegistrationReceipt($request->data()))]);
    $result = app(RegisterPerson::class)->handle($actor, (string) Str::uuid(), 'Rollback', 'rollback@example.test');
    expect($result->user_id)->toBeNull()->and($result->state)->not->toBe('confirmed')->and(User::where('email', 'rollback@example.test')->count())->toBe(0)->and(PersonProductAccess::count())->toBe(0)->and(PersonAccountLink::sole()->getRawOriginal())->toBe($link)->and($existing->fresh()->getRawOriginal())->toBe($before);
    Http::assertSentCount(1);
});
