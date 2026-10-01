<?php

use App\Actions\Auth\RecordTenantAccess;
use App\Enums\RequirementStatus;
use App\Enums\TargetScope;
use App\Enums\UserStatus;
use App\Enums\WorkosInvitationState;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ComplianceEvent;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TrainingRequirement;
use App\Models\TrainingRequirementTarget;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Models\WorkosInvitationAttempt;
use App\Models\WorkosSyncRun;
use App\Services\Requirements\AssignmentMaterializationService;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function qaProviderState(): array
{
    return json_decode(file_get_contents('/private/tmp/oceanix-invite-provider.json'), true, flags: JSON_THROW_ON_ERROR);
}
function qaSaveProviderState(array $state): void
{
    file_put_contents('/private/tmp/oceanix-invite-provider.json', json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
}
function qaInvitation(User $person, string $state, string $id, string $date = '2026-09-01T10:00:00Z'): array
{
    return ['id' => $id, 'email' => $person->email, 'organization_id' => 'org_qa', 'state' => $state, 'created_at' => $date,
        'accepted_at' => $state === 'accepted' ? '2026-09-02T10:00:00Z' : null, 'expires_at' => '2026-09-30T10:00:00Z', 'revoked_at' => $state === 'revoked' ? '2026-09-03T10:00:00Z' : null];
}
function qaReset(): array
{
    Artisan::call('migrate:fresh', ['--force' => true]);
    $company = Company::factory()->create(['name' => 'Invitation QA Company', 'slug' => 'invitation-qa', 'workos_organization_id' => 'org_qa']);
    $other = Company::factory()->create(['name' => 'Other isolated company', 'slug' => 'invitation-other', 'workos_organization_id' => 'org_other']);
    app(TenantContext::class)->set($company);
    (new PermissionSeeder)->run();
    (new RoleSeeder)->run();
    $invitations = [];
    foreach (['QA Admin', 'QA Sync Operator', 'QA Invite Operator', 'QA Denied Operator', 'Pending Recipient', 'Accepted Recipient', 'Expired Recipient', 'Revoked Recipient', 'No Invitation Recipient', 'Deleted Invitation Recipient', 'Malformed Recipient', 'Failure Recipient', 'Member Recipient', 'Suspended Recipient', 'Terminated Recipient', 'External Signin Recipient', 'Race Recipient', 'Callback Recipient', 'Invalid Identity Recipient', 'Inactive Account Recipient', 'Legacy Active Recipient', 'Platform Entry Recipient'] as $name) {
        $account = Account::factory()->create(['name' => $name, 'email' => str($name)->slug().'@qa.example', 'status' => $name === 'Inactive Account Recipient' ? 'inactive' : 'active', 'is_platform_admin' => $name === 'Platform Entry Recipient', 'workos_user_id' => null]);
        $person = User::factory()->create(['account_id' => $account->id, 'name' => $name, 'email' => $account->email, 'workos_user_id' => null,
            'status' => match ($name) {
                'Suspended Recipient' => UserStatus::Suspended, 'Terminated Recipient' => UserStatus::Terminated, 'Legacy Active Recipient', 'QA Admin', 'QA Sync Operator', 'QA Invite Operator', 'QA Denied Operator' => UserStatus::Active, default => UserStatus::Invited
            }]);
        $person->roles()->attach(Role::where('key', in_array($name, ['QA Admin', 'Platform Entry Recipient']) ? 'admin' : 'employee')->firstOrFail());
        if ($name === 'QA Sync Operator') {
            $profile = Role::factory()->create(['name' => 'QA Sync profile', 'key' => 'qa-sync']);
            $profile->permissions()->attach(Permission::whereIn('key', App\Enums\Permission::withPrerequisites([App\Enums\Permission::PeopleSyncWorkos]))->pluck('id'));
            $person->roles()->attach($profile);
        }
        if ($name === 'QA Invite Operator') {
            $profile = Role::factory()->create(['name' => 'QA Invite profile', 'key' => 'qa-invite']);
            $profile->permissions()->attach(Permission::whereIn('key', App\Enums\Permission::withPrerequisites([App\Enums\Permission::PeopleInvite]))->pluck('id'));
            $person->roles()->attach($profile);
        }
        if ($name === 'QA Denied Operator') {
            $profile = Role::factory()->create(['name' => 'QA View profile', 'key' => 'qa-view']);
            $profile->permissions()->attach(Permission::where('key', 'people.view')->firstOrFail());
            $person->roles()->attach($profile);
        }
        $state = match ($name) {
            'Pending Recipient' => 'pending', 'Accepted Recipient' => 'accepted', 'Expired Recipient', 'Member Recipient' => 'expired', 'Revoked Recipient' => 'revoked', default => null
        };
        if ($state) {
            $id = 'inv_'.str($name)->slug('_');
            $person->forceFill(['workos_invitation_id' => $id, 'invitation_state' => WorkosInvitationState::from($state), 'invitation_verified_at' => now()->subDay()])->save();
            $invitations[] = qaInvitation($person, $state, $id);
        }
        if ($name === 'Deleted Invitation Recipient') {
            $person->forceFill(['workos_invitation_id' => 'inv_deleted'])->save();
        }
        if ($name === 'Malformed Recipient') {
            $invitations[] = qaInvitation($person, 'unsupported', 'inv_malformed');
        }
        if ($name === 'Failure Recipient') {
            $person->forceFill(['invitation_state' => WorkosInvitationState::Pending, 'invitation_verified_at' => now()->subDay()])->save();
        }
        if (in_array($name, ['Member Recipient', 'External Signin Recipient'])) {
            $person->forceFill(['workos_user_id' => 'user_'.str($name)->slug('_')])->save();
        }
        if ($name === 'No Invitation Recipient') {
            $invitations[] = qaInvitation($person, 'accepted', 'inv_old', '2026-08-01T10:00:00Z');
            $invitations[] = qaInvitation($person, 'expired', 'inv_discovered', '2026-09-20T10:00:00Z');
            $foreign = qaInvitation($person, 'accepted', 'inv_foreign', '2026-09-25T10:00:00Z');
            $foreign['organization_id'] = 'org_other';
            $invitations[] = $foreign;
        }
    }
    $department = Department::factory()->create(['name' => 'QA Operations']);
    $function = JobFunction::factory()->create(['name' => 'QA Offshore technicians']);
    User::where('name', 'like', '%Recipient')->get()->each(function ($person) use ($department, $function) {
        $person->departments()->attach($department);
        $person->jobFunctions()->attach($function);
    });
    User::factory()->count(26)->create(['name' => 'Pagination Long Offshore Person', 'workos_user_id' => null, 'status' => UserStatus::Invited]);
    $course = Course::factory()->create(['title' => 'QA Published Safety Course']);
    $version = CourseVersion::factory()->published()->create(['course_id' => $course->id]);
    $course->update(['current_published_version_id' => $version->id]);
    $requirement = TrainingRequirement::factory()->create(['name' => 'QA All Status Requirement', 'course_id' => $course->id, 'status' => RequirementStatus::Active]);
    TrainingRequirementTarget::factory()->create(['training_requirement_id' => $requirement->id, 'scope_type' => TargetScope::Department, 'department_id' => $department->id]);
    app(AssignmentMaterializationService::class)->materialize($requirement);
    $callbackPerson = User::where('name', 'Callback Recipient')->firstOrFail();
    app(TenantContext::class)->set($other);
    User::factory()->create(['account_id' => $callbackPerson->account_id, 'email' => $callbackPerson->email, 'name' => 'Switch Target Recipient', 'status' => UserStatus::Invited, 'workos_user_id' => null]);
    (new PermissionSeeder)->run();
    (new RoleSeeder)->run();
    app(TenantContext::class)->set($company);
    qaSaveProviderState(['invitations' => $invitations, 'requests' => [], 'failure' => '', 'race' => '', 'uncertain' => '', 'malformed' => 'yes']);

    return ['company' => $company->slug, 'people' => User::count(), 'safe_provider' => 'in-process Http fake; stray requests prevented', 'database' => '/private/tmp/oceanix-invite-qa.sqlite'];
}
function qaInstallProviderStub(): void
{
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        $state = qaProviderState();
        $path = parse_url($request->url(), PHP_URL_PATH);
        $body = $request->data();
        $state['requests'][] = ['method' => $request->method(), 'path' => $path, 'email' => $body['email'] ?? null, 'after' => $body['after'] ?? null];
        qaSaveProviderState($state);
        if ($path === '/user_management/authenticate') {
            $person = User::withoutGlobalScope('company')->where('name', $body['code'] ?? '')->firstOrFail();

            return Http::response(['user' => ['id' => 'user_callback_'.$person->id, 'email' => $person->email, 'email_verified' => $person->name !== 'Invalid Identity Recipient']]);
        }
        if (str_contains($path, '/organization_memberships')) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'membership_created_qa'], 201);
            }

            return Http::response(['data' => ($body['user_id'] ?? '') === 'user_member_recipient' ? [['id' => 'membership_qa', 'user_id' => $body['user_id'], 'organization_id' => $body['organization_id'], 'status' => 'active']] : []]);
        }
        if (str_contains($path, '/users/')) {
            $id = basename($path);
            $person = User::withoutGlobalScope('company')->where('workos_user_id', $id)->first();

            return Http::response(['id' => $id, 'email' => $person?->email, 'last_sign_in_at' => '2026-09-29T10:00:00Z']);
        }
        if ($request->method() === 'POST') {
            if ($state['uncertain'] === 'yes') {
                return Http::response([], 503);
            }
            $person = isset($body['email']) ? User::withoutGlobalScope('company')->where('email', $body['email'])->firstOrFail() : User::withoutGlobalScope('company')->where('workos_invitation_id', basename(dirname($path)))->firstOrFail();
            $id = isset($body['email']) ? 'inv_reissued_'.$person->id.'_'.count($state['requests']) : $person->workos_invitation_id;
            $snapshot = qaInvitation($person, 'pending', $id, now()->toIso8601String());
            $state['invitations'] = array_values(array_filter($state['invitations'], fn ($row) => $row['id'] !== $id));
            $state['invitations'][] = $snapshot;
            qaSaveProviderState($state);

            return Http::response($snapshot, 201);
        }
        if ($path === '/user_management/invitations') {
            $person = User::withoutGlobalScope('company')->where('email', $body['email'] ?? '')->first();
            if ($person?->name === 'Failure Recipient' && filled($state['failure'])) {
                if ($state['failure'] === 'timeout') {
                    throw new ConnectionException('isolated timeout');
                }

                return Http::response([], (int) $state['failure']);
            }
            if ($person?->name === 'Race Recipient' && filled($state['race'])) {
                if ($state['race'] === 'login') {
                    app(RecordTenantAccess::class)->handle($person);
                } else {
                    $person->forceFill(['workos_invitation_id' => 'inv_newer_race', 'invitation_generation' => $person->invitation_generation + 1])->save();
                }
                $state['race'] = '';
                qaSaveProviderState($state);
            }
            $rows = array_values(array_filter($state['invitations'], fn ($row) => strtolower($row['email']) === strtolower($body['email'] ?? '')));
            if (($state['malformed'] ?? 'yes') === 'no') {
                $rows = array_map(fn ($row) => $row['state'] === 'unsupported' ? array_replace($row, ['state' => 'pending']) : $row, $rows);
            }
            $offset = ($body['after'] ?? null) === 'qa-next' ? 1 : 0;

            return Http::response(['data' => array_slice($rows, $offset, $offset === 0 ? 1 : 100), 'list_metadata' => ['after' => count($rows) > 1 && $offset === 0 ? 'qa-next' : null]]);
        }
        foreach ($state['invitations'] as $row) {
            if ($row['id'] === basename($path)) {
                return Http::response($row);
            }
        }

        return Http::response([], 404);
    });
}
function qaState(): array
{
    $company = Company::where('slug', 'invitation-qa')->firstOrFail();
    app(TenantContext::class)->set($company);

    return ['people' => User::orderBy('name')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'status' => $p->status->value, 'invitation' => $p->invitation_state?->value, 'current_invitation_id' => $p->workos_invitation_id, 'generation' => $p->invitation_generation, 'first_access' => $p->first_access_at?->toIso8601String(), 'last_access' => $p->last_access_at?->toIso8601String(), 'external_signin' => $p->workos_last_sign_in_at?->toIso8601String(), 'verified' => $p->invitation_verified_at?->toIso8601String(), 'error' => $p->invitation_check_error, 'member' => $p->workos_active_membership, 'accepted_history' => $p->invitation_accepted_history_at?->toIso8601String()]),
        'runs' => WorkosSyncRun::all(), 'attempts' => WorkosInvitationAttempt::all(),
        'assignments' => UserTrainingAssignment::get(['id', 'user_id', 'course_version_id', 'training_requirement_id', 'status', 'cycle_number']),
        'compliance_events' => ComplianceEvent::count(), 'queued_jobs' => DB::table('jobs')->count(), 'audits' => AuditLog::get(['action', 'actor_id', 'metadata']), 'provider_requests' => qaProviderState()['requests']];
}
