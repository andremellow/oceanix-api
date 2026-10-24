<?php

namespace App\Services\Platform;

use App\Services\Settings\ApplicationSettings;
use Oceanix\Platform\Contracts\CompanyContextHook;

class ComplianceCompanyContextHook implements CompanyContextHook
{
    public function apply(): void
    {
        app(ApplicationSettings::class)->apply();
    }
}
