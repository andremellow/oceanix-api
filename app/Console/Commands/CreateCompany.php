<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateCompany extends Command
{
    protected $signature = 'oceanix:create-company {name} {--slug=}';

    protected $description = 'Create a tenant and its baseline access profiles';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $slug = Str::slug((string) ($this->option('slug') ?: $name));

        if ($name === '' || $slug === '') {
            $this->error('A valid company name and slug are required.');

            return self::FAILURE;
        }

        if (Company::query()->where('slug', $slug)->exists()) {
            $this->error("The company slug '{$slug}' is already in use.");

            return self::FAILURE;
        }

        $company = Company::query()->create([
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
        ]);

        app(TenantContext::class)->set($company);
        (new PermissionSeeder)->run();
        (new RoleSeeder)->run();

        $this->info('Company created. Login URL: '.route('tenant.login', $company));

        return self::SUCCESS;
    }
}
