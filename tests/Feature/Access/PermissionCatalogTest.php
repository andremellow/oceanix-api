<?php

use App\Enums\Permission;
use App\Enums\PlatformPermission;
use App\Models\Permission as PermissionModel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

it('projects every enum case into the permissions table', function (): void {
    (new PermissionSeeder)->run();

    expect(PermissionModel::query()->pluck('key')->sort()->values()->all())
        ->toBe(collect(Permission::values())->sort()->values()->all());
});

it('expands prerequisites when a dependent permission is granted', function (): void {
    $keys = Permission::withPrerequisites([Permission::CoursesPublish]);

    expect($keys)->toContain(Permission::CoursesPublish->value)
        ->toContain(Permission::CoursesUpdate->value)
        ->toContain(Permission::CoursesView->value);
});

it('defines atomic shared library permissions with their exact prerequisites', function (): void {
    expect(Permission::withPrerequisites([Permission::SharedCoursesAdd]))
        ->toEqualCanonicalizing([
            Permission::SharedCoursesAdd->value,
            Permission::SharedCoursesView->value,
            Permission::CoursesView->value,
        ])
        ->and(Permission::withPrerequisites([Permission::SharedCoursesRemove]))
        ->toEqualCanonicalizing([
            Permission::SharedCoursesRemove->value,
            Permission::SharedCoursesView->value,
            Permission::CoursesView->value,
        ])
        ->and(Permission::withPrerequisites([Permission::SharedModulesUse]))
        ->toEqualCanonicalizing([
            Permission::SharedModulesUse->value,
            Permission::SharedModulesView->value,
            Permission::CoursesView->value,
            Permission::CoursesUpdate->value,
        ]);

    expect(Permission::SharedCoursesView->group())->toBe('shared-courses')
        ->and(Permission::SharedModulesView->group())->toBe('shared-modules');
});

it('keeps platform-only abilities out of the tenant permission catalog', function (): void {
    $tenantKeys = Permission::values();
    $platformKeys = array_column(PlatformPermission::cases(), 'value');

    expect($platformKeys)->toContain('shared-modules.create', 'shared-modules.update', 'shared-modules.publish', 'shared-modules.archive')
        ->and($tenantKeys)->not->toContain('shared-modules.create', 'shared-modules.update', 'shared-modules.publish', 'shared-modules.archive');
});

it('does not grant course or people modules just to create assignments', function (): void {
    $keys = Permission::withPrerequisites([Permission::AssignmentsCreate]);

    expect($keys)->toContain(Permission::AssignmentsCreate->value)
        ->toContain(Permission::AssignmentsView->value)
        ->not->toContain(Permission::CoursesView->value)
        ->not->toContain(Permission::PeopleView->value);
});

it('lets an administrator remove course and people access from an assignment creator profile', function (): void {
    seedAccessCatalog();
    $role = Role::factory()->create(['is_protected' => false]);
    $role->permissions()->attach(PermissionModel::query()->whereIn('key', [
        Permission::AssignmentsCreate->value,
        Permission::AssignmentsView->value,
        Permission::CoursesView->value,
        Permission::PeopleView->value,
    ])->pluck('id'));

    Livewire\Livewire::actingAs(adminUser())
        ->test('admin.access-profile', ['role' => $role])
        ->set('selected', [Permission::AssignmentsCreate->value])
        ->call('save')
        ->assertHasNoErrors();

    $keys = $role->permissions()->pluck('key')->all();

    expect($keys)->toContain(Permission::AssignmentsCreate->value)
        ->toContain(Permission::AssignmentsView->value)
        ->not->toContain(Permission::CoursesView->value)
        ->not->toContain(Permission::PeopleView->value);
});

it('gives the auditor profile read access without any write permission', function (): void {
    seedAccessCatalog();

    $auditor = Role::query()->where('key', 'auditor')->firstOrFail();
    $keys = $auditor->permissions()->pluck('key')->all();

    expect($keys)->toContain(Permission::AssignmentsView->value)
        ->not->toContain(Permission::AssignmentsCancel->value)
        ->not->toContain(Permission::CoursesPublish->value);
});

it('leaves the administrator profile without explicit permissions because the Gate bypasses it', function (): void {
    seedAccessCatalog();

    $admin = Role::query()->where('key', 'admin')->firstOrFail();

    expect($admin->permissions()->count())->toBe(0);

    $user = User::factory()->create();
    $user->roles()->attach($admin);

    expect($user->fresh()->can(Permission::CoursesPublish->value))->toBeTrue();
});

it('presents permissions as user-facing names and descriptions without technical keys', function (): void {
    seedAccessCatalog();
    $administrator = adminUser();
    $role = Role::query()->where('key', 'auditor')->firstOrFail();

    Livewire\Livewire::actingAs($administrator)
        ->test('admin.access-profile', ['role' => $role])
        ->assertSee(Permission::ComplianceDashboardView->label())
        ->assertSee(Permission::ComplianceDashboardView->description())
        ->assertSee(Permission::ComplianceReportsExport->description())
        ->assertDontSeeHtml('<code>')
        ->assertDontSee('Requires:');
});
