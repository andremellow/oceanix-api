<?php

namespace App\Actions\Platform;

use App\Models\Company;
use App\Services\Platform\PlatformAccess;
use App\Services\Workos\WorkosOrganizationService;

class ProvisionCompanyInWorkos
{
    public function __construct(
        private readonly WorkosOrganizationService $workos,
        private readonly PlatformAccess $access,
    ) {}

    public function handle(Company $company): Company
    {
        $this->access->authorize();

        $company->forceFill([
            'workos_organization_id' => $this->workos->provision($company),
        ])->save();

        return $company->fresh();
    }
}
