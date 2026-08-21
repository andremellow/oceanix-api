<?php

use App\Enums\Permission;
use App\Jobs\SendWorkosInvitation;
use App\Models\User;
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
        ->assertHasNoErrors();

    Queue::assertPushed(SendWorkosInvitation::class, 2);
    Queue::assertPushed(fn (SendWorkosInvitation $job): bool => $job->personId === $first->id
        && $job->companyId === currentCompany()->id
        && $job->initiatedBy === $operator->id);
});

it('queues every eligible person who has not already been invited', function (): void {
    currentCompany()->update(['workos_organization_id' => 'org_company']);
    $operator = userWithPermissions([Permission::PeopleInvite]);
    $pending = User::factory()->create();
    User::factory()->create(['workos_invitation_id' => 'invitation_existing', 'invitation_sent_at' => now()]);
    User::factory()->terminated()->create();
    Queue::fake();

    Livewire::actingAs($operator)
        ->test('organization.people')
        ->call('inviteAllPending')
        ->assertHasNoErrors();

    Queue::assertPushed(SendWorkosInvitation::class, 2);
    Queue::assertPushed(fn (SendWorkosInvitation $job): bool => $job->personId === $pending->id);
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
