<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use Illuminate\Database\Seeder;

/** Projects the permission catalog (the enum) into the database. Safe to re-run. */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionEnum::cases() as $permission) {
            Permission::query()->updateOrCreate(
                ['key' => $permission->value],
                ['label' => $permission->label(), 'group' => $permission->group()],
            );
        }

        // A permission removed from the enum must not linger as a grantable row.
        Permission::query()->whereNotIn('key', PermissionEnum::values())->delete();
    }
}
