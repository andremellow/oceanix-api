<?php

use App\Actions\People\ImportPeople;
use App\Actions\Platform\GrantPlatformCompanyAccess;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\ComplianceEvent;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Requirements\AssignmentMaterializationService;
use Illuminate\Support\Facades\Http;

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
    $this->actingAs(userWithPermissions([Permission::PeopleImport]));
    $protected = User::factory()->suspended()->create(['email' => 'protected@example.com']);
    app(ImportPeople::class)->handle([
        ['row' => 2, 'name' => 'New Person', 'email' => 'new@example.com', 'job_function' => '', 'department' => ''],
        ['row' => 3, 'name' => 'Protected', 'email' => 'protected@example.com', 'job_function' => '', 'department' => ''],
    ], [], []);
    expect(User::where('email', 'new@example.com')->first()->status)->toBe(UserStatus::Invited)->and($protected->fresh()->status)->toBe(UserStatus::Suspended);
});

it('grants a new tenant administrator invited and preserves existing suspended status', function () {
    currentCompany()->update(['workos_organization_id' => 'org_admin']);
    config(['services.workos.api_key' => 'test-key']);
    $account = Account::factory()->platformAdmin()->create();
    $this->withSession(['platform_account_id' => $account->id]);
    Http::fake(['*' => Http::sequence()->push(['data' => []])->push(['id' => 'membership_admin'])->push(['data' => []])->push(['id' => 'membership_again'])]);
    $action = app(GrantPlatformCompanyAccess::class);
    $person = $action->handle(currentCompany());
    expect($person->status)->toBe(UserStatus::Invited)->and($person->first_access_at)->toBeNull()->and($person->hasRole('admin'))->toBeTrue();
    $person->forceFill(['status' => UserStatus::Suspended])->save();
    expect($action->handle(currentCompany())->status)->toBe(UserStatus::Suspended);
});
