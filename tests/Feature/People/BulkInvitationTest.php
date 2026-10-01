<?php

use App\Enums\Permission;
use App\Models\User;
use App\Models\WorkosInvitationAttempt;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('queues invitations for selected people', function (): void {
    currentCompany()->update(['workos_organization_id' => 'org_company']);
    $operator = userWithPermissions([Permission::PeopleInvite]);
    $first = User::factory()->create();
    $second = User::factory()->create();
    Queue::fake();

    Livewire::actingAs($operator)
        ->test('organization.people')
        ->set('selected', [$first->id, $second->id])
        ->call('inviteSelected')
        ->assertSet('confirmingInvitations', true)
        ->call('queueInvitations')
        ->assertHasNoErrors();

    expect(WorkosInvitationAttempt::count())->toBe(2);
    expect(WorkosInvitationAttempt::where('person_id', $first->id)->where('actor_id', $operator->id)->exists())->toBeTrue();

});

it('queues every eligible unaccepted candidate including previously invited people', function (): void {
    currentCompany()->update(['workos_organization_id' => 'org_company']);
    $operator = userWithPermissions([Permission::PeopleInvite]);
    $pending = User::factory()->create();
    $existing = User::factory()->create(['workos_invitation_id' => 'invitation_existing', 'invitation_sent_at' => now()]);
    User::factory()->terminated()->create();
    Queue::fake();

    Livewire::actingAs($operator)
        ->test('organization.people')
        ->call('inviteAllPending')
        ->call('queueInvitations')
        ->assertHasNoErrors();

    expect(WorkosInvitationAttempt::count())->toBe(3);
    expect(WorkosInvitationAttempt::where('person_id', $pending->id)->exists())->toBeTrue()
        ->and(WorkosInvitationAttempt::where('person_id', $existing->id)->exists())->toBeTrue();

});

it('denies bulk invitations without the invitation permission', function (): void {
    currentCompany()->update(['workos_organization_id' => 'org_company']);
    $operator = userWithPermissions([Permission::PeopleView]);
    Queue::fake();

    Livewire::actingAs($operator)
        ->test('organization.people')
        ->call('inviteAllPending')
        ->assertForbidden();

    Queue::assertNothingPushed();
});
