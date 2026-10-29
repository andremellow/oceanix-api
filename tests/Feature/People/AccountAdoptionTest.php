<?php

beforeEach(fn () => config(['services.account.invitation_ownership_enabled' => true]));

use App\Actions\People\AdoptPeopleIntoAccount;
use App\Actions\People\ReconcileWorkosInvitations;
use App\Enums\ComplianceEventType;
use App\Enums\Permission;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\PersonAccountLink;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use Database\Factories\AccountFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('preserves local evidence and invitation state across repeated adoption', function () {
    Http::preventStrayRequests();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic-token', 'services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_adopt']);
    AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => 'org_adopt']);
    $actor = userWithPermissions([Permission::PeopleManage]);
    $actor->forceFill(['workos_user_id' => 'user_adopt_actor'])->save();
    $person = User::factory()->create();
    $person->forceFill(['workos_invitation_id' => 'inv_existing', 'invitation_generation' => 3])->save();
    seedAccessCatalog();
    $person->roles()->syncWithoutDetaching(Role::where('key', 'employee')->firstOrFail()->id);
    $course = Course::factory()->create();
    $assignment = UserTrainingAssignment::factory()->forCourse($course)->completed()->create(['user_id' => $person->id]);
    Certificate::factory()->create(['user_id' => $person->id, 'assignment_id' => $assignment->id, 'course_id' => $assignment->course_id, 'course_version_id' => $assignment->course_version_id]);
    app(ComplianceEventRecorder::class)->record(ComplianceEventType::CourseCompleted, $person->id, ['assignment_id' => $assignment->id, 'course_version_id' => $assignment->course_version_id]);
    $before = DB::table('users')->orderBy('id')->get()->toJson();
    $evidence = [];
    foreach (['user_training_assignments', 'certificates', 'compliance_events', 'role_user'] as $table) {
        $evidence[$table] = DB::table($table)->get()->toJson();
    }
    $adopt = app(AdoptPeopleIntoAccount::class);
    expect($adopt->handle($actor, personIds: [$person->id])[0]['outcome'])->toBe('ready');
    expect(PersonAccountLink::count())->toBe(0);
    Http::assertNothingSent();
    Http::fake(['account.example.test/*' => function ($request) {
        return Http::response(array_intersect_key($request->data(), array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['mapping_complete' => true, 'local_person_id' => (string) $request['payload']['local_person_id'], 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid()]]);
    }]);
    expect($adopt->handle($actor, dryRun: false, personIds: [$person->id])[0]['outcome'])->toBe('confirmed');
    expect($adopt->handle($actor, dryRun: false, personIds: [$person->id])[0]['outcome'])->toBe('already_linked');
    expect(DB::table('users')->orderBy('id')->get()->toJson())->toBe($before)->and(PersonAccountLink::count())->toBe(1);
    foreach ($evidence as $table => $rows) {
        expect(DB::table($table)->get()->toJson())->toBe($rows);
    }
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});

it('QA-R02 QA-R03 obtains a versioned baseline for an already linked untouched person before freezing reconcile and retains old receipts', function (bool $loseAcknowledgement) {
    Http::preventStrayRequests();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_baseline']);
    $binding = AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => 'org_baseline']);
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => 'user_baseline_actor', 'account_id' => AccountFactory::new()->create(['workos_user_id' => 'user_baseline_actor', 'status' => 'active'])->id])->save();
    $person = User::factory()->create(['workos_user_id' => 'user_baseline_exact']);
    $link = PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => $binding->account_company_uuid, 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid(), 'provenance' => []]);
    $historicSourceId = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'mapping-source:'.$company->id.':'.$person->id.':'.$actor->id.':person.invitation.reconcile:0');
    $historicMappingId = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'mapping:'.$historicSourceId);
    $old = AccountRequestOperation::create(['id' => $historicMappingId, 'company_id' => $company->id, 'user_id' => $person->id, 'actor_id' => $actor->id, 'kind' => 'person.map-existing', 'actor_workos_user_id' => $actor->workos_user_id, 'payload' => ['historic' => true], 'payload_hash' => str_repeat('a', 64), 'receipt' => ['historic' => true], 'state' => 'confirmed']);
    $before = $old->fresh()->getAttributes();
    $requests = [];
    $mappingReceipt = null;
    Http::fake(['account.example.test/*' => function ($request) use ($link, $person, &$requests, &$mappingReceipt, $loseAcknowledgement) {
        if ($request->method() === 'GET') {
            return Http::response($mappingReceipt);
        }
        $input = $request->data();
        $requests[] = $input;
        $base = array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence']));
        $mapping = ['mapping_complete' => true, 'local_person_id' => (string) $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id, 'invitation_baseline' => ['invitation_id' => 'invitation_imported_baseline', 'generation' => 0]];
        $snapshot = ['local_person_id' => $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id, 'workos_user_id' => $person->workos_user_id, 'generation' => 0, 'observation_sequence' => 1, 'verified_at' => now()->toIso8601String(), 'accepted_history_at' => null, 'invitation' => ['id' => 'invitation_imported_baseline', 'state' => 'expired']];

        $receipt = $base + ['outcome' => 'confirmed', 'confirmed_state' => $input['kind'] === 'person.map-existing' ? $mapping : ['observation' => $snapshot]];
        if ($input['kind'] === 'person.map-existing') {
            $mappingReceipt = $receipt;
            if ($loseAcknowledgement) {
                return Http::failedConnection();
            }
        }

        return Http::response($receipt);
    }]);
    if ($loseAcknowledgement) {
        expect(fn () => app(ReconcileWorkosInvitations::class)->refresh($person, $actor->id))->toThrow(ConnectionException::class);
    }
    app(ReconcileWorkosInvitations::class)->refresh($person, $actor->id);
    expect($requests)->toHaveCount(2)->and($requests[0]['kind'])->toBe('person.map-existing')->and($requests[1]['payload']['invitation_id'])->toBe('invitation_imported_baseline')
        ->and($person->fresh()->workos_invitation_id)->toBe('invitation_imported_baseline')->and($person->fresh()->invitation_state->value)->toBe('expired')->and($old->fresh()->getAttributes())->toBe($before)->and(PersonAccountLink::count())->toBe(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
})->with([false, true]);

it('QA-R02 QA-R03 validates mapping baseline types and lets a concurrent real observation win', function (string $case) {
    Http::preventStrayRequests();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_baseline_guard']);
    AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => 'org_baseline_guard']);
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $actor->forceFill(['workos_user_id' => 'user_baseline_guard_actor'])->save();
    $person = User::factory()->create(['workos_user_id' => 'user_baseline_guard']);
    Http::fake(['account.example.test/*' => function ($request) use ($person, $case) {
        if ($case === 'competing-observation') {
            $person->forceFill(['workos_invitation_id' => 'invitation_real', 'invitation_observation_sequence' => 1, 'invitation_observation_hash' => str_repeat('b', 64), 'invitation_verified_at' => now()])->save();
        }
        $baseline = ['invitation_id' => 'invitation_mapping', 'generation' => $case === 'nonzero' ? 1 : ($case === 'string-generation' ? '0' : 0)];

        return Http::response(array_intersect_key($request->data(), array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['mapping_complete' => true, 'local_person_id' => (string) $person->id, 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid(), 'invitation_baseline' => $baseline]]);
    }]);
    $action = fn () => app(AdoptPeopleIntoAccount::class)->ensure($actor, $person, 'person.invitation.reconcile');
    if ($case === 'competing-observation') {
        $action();
        expect($person->fresh()->workos_invitation_id)->toBe('invitation_real')->and($person->fresh()->invitation_observation_sequence)->toBe(1)->and($person->fresh()->invitation_observation_hash)->toBe(str_repeat('b', 64));
    } else {
        expect($action)->toThrow(HttpException::class);
        expect($person->fresh()->workos_invitation_id)->toBeNull()->and(PersonAccountLink::count())->toBe(0);
    }
})->with(['nonzero', 'string-generation', 'competing-observation']);
