<?php

use App\Enums\WorkosInvitationState;
use App\Models\User;
use App\Models\WorkosInvitationAttempt;
use Illuminate\Support\Facades\Queue;

it('exercises invitation filters and recovery confirmation at mobile and desktop widths', function () {
    $this->actingAs(adminUser());
    currentCompany()->update(['workos_organization_id' => 'org_test']);
    $person = User::factory()->create(['name' => 'Long Offshore Invitation Recipient With Separate Access Evidence']);
    $person->forceFill(['invitation_state' => WorkosInvitationState::Pending])->save();
    Queue::fake();
    $page = visit(route('people.index', [], false))->resize(1440, 900)->assertSee('Invitation status')->assertSee('Open training')->assertSee('No recorded access')
        ->select('select[name="invitation_status"]', 'pending')->assertSee($person->name)
        ->select('select[name="invitation_status"]', 'accepted')->assertSee('No people match these filters')
        ->click('Clear filters')->click('button[wire\\:click="inviteAllPending"]')->assertSee('Confirm invitation recovery')
        ->keys('dialog[open] button[wire\\:click="queueInvitations"]', 'Escape')->assertMissing('dialog[open]')
        ->resize(320, 568)->assertSee('Synchronize WorkOS')->assertSee('Invitation status')->assertNoJavascriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(filename: 'people-invitation-mobile');
});

it('queues exactly the visible checkbox recipient after filter morphing', function () {
    $this->actingAs(adminUser());
    currentCompany()->update(['workos_organization_id' => 'org_test']);
    $accepted = User::factory()->create(['name' => 'Accepted Identity Control']);
    $accepted->forceFill(['invitation_state' => WorkosInvitationState::Accepted])->save();
    $pending = User::factory()->create(['name' => 'Pending Identity Recipient']);
    $pending->forceFill(['invitation_state' => WorkosInvitationState::Pending])->save();
    $expired = User::factory()->create(['name' => 'Expired Identity Recipient']);
    $expired->forceFill(['invitation_state' => WorkosInvitationState::Expired])->save();
    Queue::fake();
    $page = visit(route('people.index', [], false))->resize(320, 568)
        ->select('select[name="invitation_status"]', 'pending')
        ->click('[aria-label="Select Pending Identity Recipient"]')
        ->click('button[wire\\:click="inviteSelected"]')->assertSee('This action covers 1 selected people.')
        ->click('dialog[open] button[wire\\:click="queueInvitations"]')->assertMissing('dialog[open]')->assertSee('1 invitation queued');
    expect(WorkosInvitationAttempt::pluck('person_id')->all())->toBe([$pending->id]);
    $page->select('select[name="invitation_status"]', 'expired')
        ->click('[aria-label="Select Expired Identity Recipient"]')
        ->click('button[wire\\:click="inviteSelected"]')
        ->click('dialog[open] button[wire\\:click="queueInvitations"]')->assertMissing('dialog[open]')->assertSee('1 invitation queued')->assertNoJavascriptErrors();
    expect(WorkosInvitationAttempt::orderBy('id')->pluck('person_id')->all())->toBe([$pending->id, $expired->id]);
});

it('uses cancellable one-person Flux recovery confirmation without native dialogs', function () {
    $this->actingAs(adminUser());
    currentCompany()->update(['workos_organization_id' => 'org_test']);
    $person = User::factory()->create(['name' => 'Revoked Detail Recipient']);
    $person->forceFill(['invitation_state' => WorkosInvitationState::Revoked])->save();
    Queue::fake();
    $page = visit(route('people.show', ['user' => $person], false))
        ->click('button[wire\\:click="confirmInvitation"]')->assertSee('This action covers one person: Revoked Detail Recipient ('.$person->email.').')
        ->assertSee('Selected revoked invitations will receive a new invitation after verification.')
        ->keys('dialog[open] button[wire\\:click="sendInvitation"]', 'Escape')->assertMissing('dialog[open]');
    expect(WorkosInvitationAttempt::count())->toBe(0);
    $page->click('button[wire\\:click="confirmInvitation"]')->click('dialog[open] button[wire\\:click="sendInvitation"]')->assertMissing('dialog[open]')->assertSee('Invitation queued through WorkOS.')->assertNoJavascriptErrors();
    expect(WorkosInvitationAttempt::pluck('person_id')->all())->toBe([$person->id]);
});
