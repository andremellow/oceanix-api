<?php

use App\Enums\Permission;
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
