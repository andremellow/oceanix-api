<?php

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Enums\WorkosInvitationState;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\User;
use Livewire\Livewire;

it('combines filters with consistent counts and separate access evidence', function () {
    $department = Department::factory()->create();
    $function = JobFunction::factory()->create();
    $person = User::factory()->create(['name' => 'Unique Offshore Technician', 'status' => UserStatus::Invited]);
    $person->forceFill(['invitation_state' => WorkosInvitationState::Pending])->save();
    $person->departments()->attach($department);
    $person->jobFunctions()->attach($function);
    $other = User::factory()->create(['name' => 'Unique Already Accessed', 'status' => UserStatus::Invited]);
    $other->forceFill(['invitation_state' => WorkosInvitationState::Pending, 'first_access_at' => now()])->save();
    Livewire::actingAs(userWithPermissions([Permission::PeopleView]))->test('organization.people')
        ->set('search', 'Unique')->set('department_id', (string) $department->id)->set('job_function_id', (string) $function->id)->set('status', 'invited')->set('invitation_status', 'pending')->set('no_access', true)
        ->assertSee($person->name)->assertDontSee($other->name)->assertSee(__('Open training'))->assertSee(__('Overdue training'))->assertSee(__('No recorded access'))
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
