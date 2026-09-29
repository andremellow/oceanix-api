<?php

namespace App\Services\Tenancy;

use App\Models\Company;

class CompanyComplianceAccess
{
    public function assertEnabled(Company|int|null $company): void
    {
        $id = $company instanceof Company ? $company->id : $company;
        abort_unless($id && Company::whereKey($id)->where('compliance_access_enabled', true)->exists(), 403, 'Compliance access is disabled.');
    }
}
