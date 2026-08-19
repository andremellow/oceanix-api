<?php

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Grant a set of permissions through a throwaway access profile — the same path the
 * application uses, so the Gate resolution under test is the real one.
 *
 * @param  list<PermissionEnum|string>  $permissions
 */
function grantPermissions(User $user, array $permissions, string $profileName = 'Test profile'): Role
{
    $role = Role::factory()->create(['name' => $profileName, 'key' => str($profileName)->slug()->toString()]);

    $ids = collect(PermissionEnum::withPrerequisites($permissions))
        ->map(function (string $key): int {
            $permission = PermissionEnum::from($key);

            return Permission::query()->firstOrCreate(
                ['key' => $permission->value],
                ['label' => $permission->label(), 'group' => $permission->group()],
            )->id;
        });

    $role->permissions()->sync($ids);
    $user->roles()->attach($role);

    return $role;
}

/** @param list<PermissionEnum|string> $permissions */
function userWithPermissions(array $permissions): User
{
    $user = User::factory()->create();
    grantPermissions($user, $permissions, 'Profile '.str()->random(8));

    return $user->fresh();
}

function adminUser(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->firstOrCreate(
        ['key' => 'admin'],
        ['name' => 'Administrator', 'is_protected' => true],
    ));

    return $user->fresh();
}

/** A person with no granted permission: only their own training. */
function employeeUser(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->firstOrCreate(
        ['key' => 'employee'],
        ['name' => 'Employee', 'is_protected' => true],
    ));

    return $user->fresh();
}

function seedAccessCatalog(): void
{
    (new PermissionSeeder)->run();
    (new RoleSeeder)->run();
}
