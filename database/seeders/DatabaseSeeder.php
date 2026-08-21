<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $company = Company::query()->firstOrCreate(
            ['slug' => 'oceanix-demo'],
            ['name' => 'Oceanix Demo', 'status' => 'active'],
        );

        app(TenantContext::class)->set($company);

        $this->call(RoleSeeder::class);

        // Sample content stays out of production.
        if (app()->environment('local', 'testing')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
