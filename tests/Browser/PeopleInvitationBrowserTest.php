<?php

use App\Enums\WorkosInvitationState;
use App\Models\User;
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
    $page->screenshot(filename: 'people-invitation-mobile');
});
