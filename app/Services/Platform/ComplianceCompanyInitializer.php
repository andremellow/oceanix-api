<?php

namespace App\Services\Platform;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Oceanix\Platform\Contracts\CompanyInitializer;

class ComplianceCompanyInitializer implements CompanyInitializer
{
    public function initialize(): void
    {
        (new PermissionSeeder)->run();
        (new RoleSeeder)->run();
    }
}
