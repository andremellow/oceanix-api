<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Catalog seeders are idempotent and belong in every environment.
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
        ]);

        // Sample content stays out of production.
        if (app()->environment('local', 'testing')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
