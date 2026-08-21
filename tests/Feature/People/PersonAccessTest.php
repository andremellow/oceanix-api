<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('allows the dedicated permission to assign an access profile from the person', function (): void {
    $operator = userWithPermissions([Permission::PeopleAssignAccessProfiles]);
    $person = User::factory()->create();
    $role = Role::factory()->create();

    Livewire::actingAs($operator)
        ->test('organization.person', ['user' => $person])
        ->call('toggleRole', $role->id)
        ->assertHasNoErrors();

    expect($person->roles()->whereKey($role->id)->exists())->toBeTrue();
});

it('prevents a non-administrator from assigning a protected access profile', function (): void {
    $operator = userWithPermissions([Permission::PeopleAssignAccessProfiles]);
    $person = User::factory()->create();
    $role = Role::factory()->create(['is_protected' => true]);

    Livewire::actingAs($operator)
        ->test('organization.person', ['user' => $person])
        ->call('toggleRole', $role->id)
        ->assertForbidden();

    expect($person->roles()->whereKey($role->id)->exists())->toBeFalse();
});

it('denies role assignment without the dedicated permission', function (): void {
    $operator = userWithPermissions([Permission::PeopleView]);
    $person = User::factory()->create();
    $role = Role::factory()->create();

    Livewire::actingAs($operator)
        ->test('organization.person', ['user' => $person])
        ->call('toggleRole', $role->id)
        ->assertForbidden();
});

it('allows a company administrator to assign an access profile', function (): void {
    $operator = adminUser();
    $person = User::factory()->create();
    $role = Role::factory()->create();

    Livewire::actingAs($operator)
        ->test('organization.person', ['user' => $person])
        ->call('toggleRole', $role->id)
        ->assertHasNoErrors();
});

it('sends a WorkOS invitation into the person company organization', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    currentCompany()->update(['workos_organization_id' => 'org_company']);
    $operator = userWithPermissions([Permission::PeopleInvite]);
    $person = User::factory()->create(['email' => 'invitee@example.com']);

    Http::fake([
        'api.workos.com/user_management/invitations' => Http::response([
            'id' => 'invitation_123',
            'organization_id' => 'org_company',
            'email' => $person->email,
        ], 201),
    ]);

    Livewire::actingAs($operator)
        ->test('organization.person', ['user' => $person])
        ->call('sendInvitation')
        ->assertHasNoErrors();

    expect($person->fresh()->workos_invitation_id)->toBe('invitation_123')
        ->and($person->fresh()->invitation_sent_at)->not->toBeNull();

    Http::assertSent(fn ($request): bool => $request['email'] === 'invitee@example.com'
        && $request['organization_id'] === 'org_company'
        && $request['locale'] === 'en-US');
});

it('denies sending invitations without the dedicated permission', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    currentCompany()->update(['workos_organization_id' => 'org_company']);
    $operator = userWithPermissions([Permission::PeopleView]);
    $person = User::factory()->create();
    Http::fake();

    Livewire::actingAs($operator)
        ->test('organization.person', ['user' => $person])
        ->call('sendInvitation')
        ->assertForbidden();

    Http::assertNothingSent();
});

it('requires the company to be provisioned before inviting', function (): void {
    config()->set('services.workos.api_key', 'sk_test');
    $operator = userWithPermissions([Permission::PeopleInvite]);
    $person = User::factory()->create();
    Http::fake();

    Livewire::actingAs($operator)
        ->test('organization.person', ['user' => $person])
        ->call('sendInvitation')
        ->assertHasErrors('invitation');

    Http::assertNothingSent();
});
