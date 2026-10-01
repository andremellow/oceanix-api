<?php

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Enums\WorkosInvitationState;
use App\Models\Company;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Models\WorkosInvitationAttempt;
use App\Models\WorkosSyncRun;
use App\Services\People\PeopleDirectory;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

it('combines filters with consistent counts and separate access evidence', function () {
    $department = Department::factory()->create();
    $function = JobFunction::factory()->create();
    $person = User::factory()->create(['name' => 'Unique Offshore Technician', 'status' => UserStatus::Invited]);
    $person->forceFill(['invitation_state' => WorkosInvitationState::Pending])->save();
    $person->departments()->attach($department);
    $person->jobFunctions()->attach($function);
    $other = User::factory()->create(['name' => 'Unique Already Accessed', 'status' => UserStatus::Invited]);
    $other->departments()->attach($department);
    $other->jobFunctions()->attach($function);
    $other->forceFill(['invitation_state' => WorkosInvitationState::Pending, 'first_access_at' => now()])->save();
    foreach ([['Active Control', UserStatus::Active, WorkosInvitationState::Pending], ['Accepted Control', UserStatus::Invited, WorkosInvitationState::Accepted]] as [$name, $status, $state]) {
        $control = User::factory()->create(['name' => 'Unique '.$name, 'status' => $status]);
        $control->forceFill(['invitation_state' => $state])->save();
        $control->departments()->attach($department);
        $control->jobFunctions()->attach($function);
    }
    Livewire::actingAs(userWithPermissions([Permission::PeopleView]))->test('organization.people')
        ->set('search', 'Unique')->set('department_id', (string) $department->id)->set('job_function_id', (string) $function->id)->set('status', 'invited')->set('invitation_status', 'pending')->set('no_access', true)
        ->assertSee($person->name)->assertDontSee($other->name)->assertDontSee('Unique Active Control')->assertDontSee('Unique Accepted Control')->assertSee(__('Open training'))->assertSee(__('Overdue training'))->assertSee(__('No recorded access'))
        ->assertViewHas('people', fn ($page) => $page->total() === 1);
});

it('orders and paginates deterministically clearing selection on filters and page changes', function () {
    User::factory()->count(26)->create(['name' => 'Same Name']);
    $actor = userWithPermissions([Permission::PeopleInvite]);
    Livewire::actingAs($actor)->test('organization.people')->assertViewHas('people', fn ($page) => $page->count() === 25 && $page->total() === 27)
        ->set('selected', [1])->set('search', 'Same')->assertSet('selected', [])->set('selected', [1])->call('gotoPage', 2)->assertSet('selected', [])
        ->assertViewHas('people', fn ($page) => $page->count() === 1);
});

it('reauthorizes hydrated pages and direct sync actions', function () {
    $actor = userWithPermissions([Permission::PeopleSyncWorkos]);
    $component = Livewire::actingAs($actor)->test('organization.people');
    $actor->roles()->update(['archived_at' => now()]);
    $component->call('synchronize')->assertForbidden();
    Livewire::actingAs(userWithPermissions([Permission::PeopleView]))->test('organization.people')->call('synchronize')->assertForbidden();
});

it('renders distinct provider local timestamps lifecycle counts and real obligations', function () {
    $actor = adminUser();
    $person = User::factory()->create(['name' => 'Populated Evidence Recipient', 'status' => UserStatus::Active]);
    $dates = ['invitation_sent_at' => '2026-08-01 10:01:00', 'invitation_accepted_at' => '2026-08-02 10:02:00', 'invitation_expires_at' => '2026-08-03 10:03:00', 'invitation_revoked_at' => '2026-08-04 10:04:00', 'invitation_verified_at' => '2026-08-05 10:05:00', 'invitation_accepted_history_at' => '2026-08-06 10:06:00', 'first_access_at' => '2026-08-07 10:07:00', 'last_access_at' => '2026-08-08 10:08:00', 'workos_last_sign_in_at' => '2026-08-09 10:09:00'];
    $person->forceFill($dates + ['invitation_state' => WorkosInvitationState::Expired, 'invitation_check_error' => 'provider_verification_failed', 'workos_active_membership' => true])->save();
    $course = invitationRequirementFixture()->course;
    UserTrainingAssignment::factory()->forCourse($course)->create(['user_id' => $person->id]);
    UserTrainingAssignment::factory()->forCourse($course)->overdue()->create(['user_id' => $person->id, 'cycle_number' => 2]);
    $success = WorkosSyncRun::create(['actor_id' => $actor->id, 'status' => 'completed', 'finished_at' => '2026-08-10 10:10:00', 'total' => 2, 'processed' => 2, 'succeeded' => 2]);
    $partial = WorkosSyncRun::create(['actor_id' => $actor->id, 'status' => 'partial_failure', 'finished_at' => '2026-08-11 10:11:00', 'total' => 3, 'processed' => 3, 'succeeded' => 1, 'skipped' => 1, 'failed' => 1, 'reason' => 'provider_verification_failed']);
    foreach (['succeeded', 'skipped', 'failed', 'delivery_unconfirmed', 'queued'] as $status) {
        WorkosInvitationAttempt::create(['person_id' => $person->id, 'actor_id' => $actor->id, 'mode' => 'selected', 'status' => $status, 'reason' => $status === 'skipped' ? 'current_member' : ($status === 'failed' ? 'provider_verification_failed' : null)]);
    }
    $list = Livewire::actingAs($actor)->test('organization.people')->set('search', $person->name)
        ->assertViewHas('lastSuccessfulSync', fn ($run) => $run->id === $success->id)->assertViewHas('latestSync', fn ($run) => $run->id === $partial->id)
        ->assertViewHas('invitationSummary', fn ($summary) => $summary === ['total' => 5, 'processed' => 4, 'succeeded' => 1, 'skipped' => 1, 'failed' => 1, 'unconfirmed' => 1])
        ->assertSee('Processed 3 of 3')->assertSee('Processed 4 of 5')->assertSee('Successful 1, skipped 1, failed 1')->assertSee('Delivery unconfirmed: 1')->assertSee('Provider verification failed')->assertSee('Already a member of this company')->assertSee('Latest check failed')
        ->assertViewHas('people', fn ($rows) => $rows->sole()->open_assignments_count === 2 && $rows->sole()->overdue_assignments_count === 1);
    $detail = Livewire::actingAs($actor)->test('organization.person', ['user' => $person])->assertSee('Expired')->assertSee('Latest check failed')->assertSee('Waiting to start');
    foreach ($dates as $field => $date) {
        $formatted = Carbon::parse($date)->format('M j, Y H:i');
        $detail->assertSee($formatted);
    }
    expect($person->fresh()->invitation_verified_at->format('Y-m-d H:i:s'))->toBe($dates['invitation_verified_at']);
});

it('reports aggregate attempts beyond the bounded recipient outcomes and empty-company state', function () {
    $actor = adminUser();
    $person = User::factory()->create();
    for ($i = 0; $i < 24; $i++) {
        WorkosInvitationAttempt::create(['person_id' => $person->id, 'actor_id' => $actor->id, 'mode' => 'selected', 'status' => 'succeeded']);
    }
    Livewire::actingAs($actor)->test('organization.people')->assertViewHas('invitationAttempts', fn ($rows) => $rows->count() === 20)->assertSee('Processed 24 of 24')->assertSee('Successful 24, skipped 0, failed 0');
    $other = Company::factory()->create();
    app(TenantContext::class)->set($other);
    // A projection can represent a truly empty tenant without deleting operational data.
    $data = app(PeopleDirectory::class)->data();
    expect($data['companyPeopleCount'])->toBe(0)->and($data['people'])->toBeEmpty()->and($data['invitationSummary']['total'])->toBe(0);
});

it('shows one-person Flux confirmation and company-wide import disclosure', function () {
    $actor = adminUser();
    $person = User::factory()->create(['name' => 'One Recipient']);
    $person->forceFill(['invitation_state' => WorkosInvitationState::Revoked])->save();
    Livewire::actingAs($actor)->test('organization.person', ['user' => $person])->call('confirmInvitation')->assertSet('confirmingInvitation', true)->assertSee('This action covers one person: One Recipient ('.$person->email.').')->assertSee('Selected revoked invitations will receive a new invitation after verification.')->assertSee('Queue invitations')->assertDontSee('wire:confirm', false);
    Livewire::actingAs($actor)->test('organization.import-people')->set('rows', [['row' => 2, 'name' => 'Imported Recipient', 'email' => 'imported@example.com', 'job_function' => '', 'department' => '']])->assertSee('Queue company-wide WorkOS invitation recovery after importing')->assertSee('Includes eligible people already in this company: pending invitations are resent, and expired or missing invitations receive a new invitation. This is not limited to the imported rows.');
});
