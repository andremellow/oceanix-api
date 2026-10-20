<?php

use App\Actions\ControlPlane\ApplyProductOperation;
use App\Actions\People\ImportPeople;
use App\Actions\Platform\GrantPlatformCompanyAccess;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\AccountCompanyBinding;
use App\Models\ComplianceEvent;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Requirements\AssignmentMaterializationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AccountIntegrationFixture;

it('preserves obligation evidence during legacy transition', function () {
    $legacy = adminUser();
    $accessed = User::factory()->create();
    $accessed->forceFill(['first_access_at' => now()->subDay()])->save();
    $suspended = User::factory()->suspended()->create();
    $terminated = User::factory()->terminated()->create();
    $requirement = invitationRequirementFixture();
    app(AssignmentMaterializationService::class)->materialize($requirement);
    $assignments = UserTrainingAssignment::all()->map->getRawOriginal()->all();
    $events = ComplianceEvent::count();
    (require database_path('migrations/2026_10_01_000002_reclassify_people_without_access_evidence.php'))->up();
    expect($legacy->fresh()->status)->toBe(UserStatus::Invited)->and($legacy->fresh()->first_access_at)->toBeNull()->and($legacy->fresh()->hasRole('admin'))->toBeTrue()
        ->and($accessed->fresh()->status)->toBe(UserStatus::Active)->and($suspended->fresh()->status)->toBe(UserStatus::Suspended)->and($terminated->fresh()->status)->toBe(UserStatus::Terminated)
        ->and(UserTrainingAssignment::all()->map->getRawOriginal()->all())->toBe($assignments)->and(ComplianceEvent::count())->toBe($events);
});

it('imports new people invited without changing existing protected statuses', function () {
    seedAccessCatalog();
    $actor = userWithPermissions([Permission::PeopleImport]);
    $actor->forceFill(['workos_user_id' => 'user_import_status_actor'])->save();
    $this->actingAs($actor);
    currentCompany()->update(['workos_organization_id' => 'org_import_status']);
    AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => currentCompany()->id, 'workos_organization_id' => 'org_import_status']);
    config(['services.account.invitation_ownership_enabled' => true, 'services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    Http::preventStrayRequests();
    Http::fake(['account.example.test/*' => function ($request) {
        $data = $request->data();
        $state = ['enabled' => true, 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid()];
        if ($data['kind'] === 'person.map-existing') {
            $state += ['mapping_complete' => true, 'local_person_id' => (string) $data['payload']['local_person_id']];
        } elseif (($data['payload']['registration_mode'] ?? null) === 'confirmed-first-mapping') {
            $state = $data['payload'] + ['mapping_complete' => true];
        } elseif ($data['kind'] === 'person.register') {
            $state += ['registration_mode' => 'confirmed-first', 'registration_source' => 'import', 'actor_workos_user_id' => $data['actor_workos_user_id'], 'request_payload_hash' => ApplyProductOperation::hash($data), 'workos_user_id' => 'user_import_status_new', 'workos_membership_id' => 'om_import_status_new', 'membership_status' => 'pending', 'mapping_complete' => false];
        }

        return Http::response(array_intersect_key($data, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence', 'actor_workos_user_id'])) + ['outcome' => 'confirmed', 'confirmed_state' => $state]);
    }]);
    $protected = User::factory()->suspended()->create(['email' => 'protected@example.com', 'workos_user_id' => 'user_import_status_existing']);
    $result = app(ImportPeople::class)->handle([
        ['row' => 2, 'name' => 'New Person', 'email' => 'new@example.com', 'job_function' => '', 'department' => ''],
        ['row' => 3, 'name' => 'Protected', 'email' => 'protected@example.com', 'job_function' => '', 'department' => ''],
    ], [], []);
    expect(User::where('email', 'new@example.com')->first()->status)->toBe(UserStatus::Invited)->and($protected->fresh()->status)->toBe(UserStatus::Suspended);
    expect($result['created'])->toBe(1)->and($result['existing'])->toBe(1)->and($result['pending'])->toBe(0);
    Http::assertSentCount(3);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});

it('grants a new tenant administrator invited and preserves existing suspended status', function () {
    currentCompany()->update(['workos_organization_id' => 'org_admin']);
    config(['services.workos.api_key' => 'test-key']);
    $account = Account::factory()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $account->id]);
    AccountIntegrationFixture::fakePlatformConnection(currentCompany());
    $action = app(GrantPlatformCompanyAccess::class);
    $person = $action->handle(currentCompany());
    expect($person->status)->toBe(UserStatus::Invited)->and($person->first_access_at)->toBeNull()->and($person->hasRole('admin'))->toBeTrue();
    $person->forceFill(['status' => UserStatus::Suspended])->save();
    expect($action->handle(currentCompany())->status)->toBe(UserStatus::Suspended);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});
