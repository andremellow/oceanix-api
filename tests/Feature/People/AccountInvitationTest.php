<?php

beforeEach(fn () => config(['services.account.invitation_ownership_enabled' => true]));

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Actions\People\SendWorkosInvitation;
use App\Enums\Permission;
use App\Enums\WorkosOperationStatus;
use App\Models\Account;
use App\Models\AccountRequestOperation;
use App\Models\AuditLog;
use App\Models\PersonAccountLink;
use App\Models\User;
use App\Models\WorkosInvitationAttempt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

it('recovers an accepted Account invitation receipt without sending again or changing first access', function () {
    Http::preventStrayRequests();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_cutover']);
    $actor = userWithPermissions([Permission::PeopleInvite]);
    $actor->forceFill(['workos_user_id' => 'user_inviter', 'account_id' => Account::factory()->create()->id])->save();
    $person = User::factory()->create(['invitation_generation' => 4, 'first_access_at' => now()->subDays(3)]);
    $link = PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => (string) Str::uuid(), 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid(), 'provenance' => []]);
    $attempt = WorkosInvitationAttempt::create(['person_id' => $person->id, 'actor_id' => $actor->id, 'mode' => 'selected', 'generation' => 4, 'status' => WorkosOperationStatus::Sending, 'send_started_at' => now()]);
    $id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'compliance:'.$company->id.':invitation:'.$attempt->id);
    $envelope = ['protocol_version' => 2, 'operation_id' => $id, 'kind' => 'person.invite', 'product' => 'compliance', 'account_company_uuid' => $link->account_company_uuid, 'workos_organization_id' => 'org_cutover', 'subject_id' => $link->company_member_id, 'resource_sequence' => 5, 'actor_workos_user_id' => 'user_inviter', 'payload' => ['local_person_id' => $person->id, 'invitation_id' => null, 'generation' => 4]];
    AccountRequestOperation::create(['id' => $id, 'company_id' => $company->id, 'user_id' => $person->id, 'actor_id' => $actor->id, 'kind' => 'person.invite', 'actor_workos_user_id' => 'user_inviter', 'payload' => $envelope, 'payload_hash' => ApplyProductOperation::hash($envelope), 'dispatched_at' => now()]);
    $firstAccess = $person->first_access_at;
    Http::fake(['account.example.test/*' => Http::response(array_intersect_key($envelope, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['generation' => 5, 'sent' => true, 'invitation' => ['id' => 'inv_cutover', 'email' => $person->email, 'organization_id' => 'org_cutover', 'state' => 'pending', 'created_at' => now()->toIso8601String()]]])]);
    app(SendWorkosInvitation::class)->execute($attempt);
    expect($attempt->fresh()->status)->toBe(WorkosOperationStatus::Succeeded)
        ->and($person->fresh()->workos_invitation_id)->toBe('inv_cutover')
        ->and($person->fresh()->invitation_generation)->toBe(5)
        ->and($person->fresh()->first_access_at->equalTo($firstAccess))->toBeTrue();
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    app(SendWorkosInvitation::class)->execute($attempt->fresh());
    Http::assertSentCount(1);
});

it('keeps generation and send audit unchanged when Account confirms an already active member', function () {
    Http::preventStrayRequests();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_active_cutover']);
    $actor = userWithPermissions([Permission::PeopleInvite]);
    $actor->forceFill(['workos_user_id' => 'user_active_inviter', 'account_id' => Account::factory()->create()->id])->save();
    $person = User::factory()->create(['invitation_generation' => 7]);
    PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => (string) Str::uuid(), 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid(), 'provenance' => []]);
    $attempt = WorkosInvitationAttempt::create(['person_id' => $person->id, 'actor_id' => $actor->id, 'mode' => 'selected', 'generation' => 7, 'status' => WorkosOperationStatus::Queued]);
    Http::fake(['account.example.test/*' => function ($request) {
        $data = $request->method() === 'GET' ? AccountRequestOperation::sole()->payload : $request->data();

        return Http::response(array_intersect_key($data, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['generation' => 7, 'sent' => false, 'active_membership' => true, 'invitation' => ['id' => null, 'state' => 'none']]]);
    }]);
    app(SendWorkosInvitation::class)->execute($attempt);
    expect($attempt->fresh()->status)->toBe(WorkosOperationStatus::Skipped)
        ->and($attempt->fresh()->reason)->toBe('current_member')
        ->and($person->fresh()->invitation_generation)->toBe(7)
        ->and($person->fresh()->invitation_sent_at)->toBeNull()
        ->and(AuditLog::where('action', 'person.workos_invitation_sent')->count())->toBe(0);
    $attempt->update(['status' => WorkosOperationStatus::Sending, 'send_started_at' => now()]);
    $person->forceFill(['workos_active_membership' => false])->save();
    app(SendWorkosInvitation::class)->execute($attempt->fresh());
    expect($attempt->fresh()->status)->toBe(WorkosOperationStatus::Skipped)->and($person->fresh()->invitation_generation)->toBe(7)->and($person->fresh()->workos_active_membership)->toBeTrue();
    expect(AuditLog::where('action', 'person.workos_invitation_sent')->count())->toBe(0);
    Http::assertSentCount(2);
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT'))->toHaveCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});
