<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Oceanix\Platform\Access\PermissionProjector;

/** Projects the configured permission catalog into the database. */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionProjector::class)->sync(prune: true);
    }
}
