<?php

use App\Enums\Permission;
use App\Enums\TargetScope;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\Role;
use App\Models\TrainingRequirement;
use App\Models\TrainingRequirementTarget;
use App\Models\User;
use App\Tenancy\TenantContext;
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

it('lets a people manager assign department and job function management scopes', function (): void {
    $operator = userWithPermissions([Permission::PeopleManage]);
    $person = User::factory()->create();
    $department = Department::factory()->create();
    $jobFunction = JobFunction::factory()->create();

    Livewire::actingAs($operator)
        ->test('organization.person', ['user' => $person])
        ->set('managedDepartmentIds', [$department->id])
        ->set('managedJobFunctionIds', [$jobFunction->id])
        ->call('saveManagementScopes')
        ->assertHasNoErrors();

    expect($person->managedDepartments()->whereKey($department->id)->exists())->toBeTrue()
        ->and($person->managedJobFunctions()->whereKey($jobFunction->id)->exists())->toBeTrue();
});

it('denies management scope assignment without people manage permission', function (): void {
    $operator = userWithPermissions([Permission::PeopleView]);
    $person = User::factory()->create();
    $department = Department::factory()->create();

    Livewire::actingAs($operator)
        ->test('organization.person', ['user' => $person])
        ->set('managedDepartmentIds', [$department->id])
        ->call('saveManagementScopes')
        ->assertForbidden();

    expect($person->managedDepartments()->exists())->toBeFalse();
});

it('rejects a management scope from another company', function (): void {
    $company = currentCompany();
    $operator = adminUser();
    $person = User::factory()->create();
    $otherCompany = Company::factory()->create();
    app(TenantContext::class)->set($otherCompany);
    $foreignDepartment = Department::factory()->create();
    app(TenantContext::class)->set($company);

    Livewire::actingAs($operator)
        ->test('organization.person', ['user' => $person])
        ->set('managedDepartmentIds', [$foreignDepartment->id])
        ->call('saveManagementScopes')
        ->assertHasErrors('management');

    expect($person->managedDepartments()->exists())->toBeFalse();
});

it('renders person links without an empty company query parameter', function (): void {
    $operator = adminUser();
    $person = User::factory()->create();
    $expected = route('people.show', ['company' => currentCompany(), 'user' => $person]);

    Livewire::actingAs($operator)
        ->test('organization.people')
        ->assertSee($expected, escape: false)
        ->assertDontSee($expected.'?company=', escape: false);
});

it('shows the persons upcoming three month training schedule', function (): void {
    $person = User::factory()->create();
    $course = Course::factory()->create(['title' => 'Recurring safety']);
    $version = CourseVersion::factory()->published()->create(['course_id' => $course]);
    $course->update(['current_published_version_id' => $version->id]);
    $requirement = TrainingRequirement::factory()->create([
        'course_id' => $course,
        'name' => 'Monthly safety rule',
        'frequency_value' => 1,
        'due_days_after_assignment' => 10,
    ]);
    TrainingRequirementTarget::factory()->create([
        'training_requirement_id' => $requirement,
        'scope_type' => TargetScope::Everyone,
    ]);

    Livewire::actingAs(adminUser())
        ->test('organization.person', ['user' => $person])
        ->assertSee(__('Upcoming training schedule'))
        ->assertSee('Monthly safety rule')
        ->assertSee('Recurring safety');
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

it('requires an exact email confirmation before granting or removing administrator access', function (): void {
    seedAccessCatalog();
    $operator = adminUser();
    $person = User::factory()->create(['email' => 'critical.person@example.com']);
    $administratorRole = Role::query()->where('key', 'admin')->firstOrFail();

    $component = Livewire::actingAs($operator)
        ->test('organization.person', ['user' => $person])
        ->call('toggleRole', $administratorRole->id)
        ->assertSet('confirmingAdministratorRoleId', $administratorRole->id);

    expect($person->roles()->whereKey($administratorRole->id)->exists())->toBeFalse();

    $component->set('administratorConfirmation', 'wrong@example.com')
        ->call('confirmAdministratorRole')
        ->assertHasErrors('administratorConfirmation');

    expect($person->roles()->whereKey($administratorRole->id)->exists())->toBeFalse();

    $component->set('administratorConfirmation', $person->email)
        ->call('confirmAdministratorRole')
        ->assertHasNoErrors();

    expect($person->roles()->whereKey($administratorRole->id)->exists())->toBeTrue();

    $component->call('toggleRole', $administratorRole->id);
    expect($person->roles()->whereKey($administratorRole->id)->exists())->toBeTrue();

    $component->set('administratorConfirmation', $person->email)
        ->call('confirmAdministratorRole')
        ->assertHasNoErrors();

    expect($person->roles()->whereKey($administratorRole->id)->exists())->toBeFalse();
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
