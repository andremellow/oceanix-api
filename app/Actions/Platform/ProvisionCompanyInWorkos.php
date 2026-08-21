<?php

namespace App\Actions\Platform;

use App\Models\Company;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\PlatformAccess;
use App\Services\Workos\WorkosOrganizationService;
use App\Tenancy\TenantContext;

class ProvisionCompanyInWorkos
{
    public function __construct(
        private readonly WorkosOrganizationService $workos,
        private readonly PlatformAccess $access,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Company $company): Company
    {
        $this->access->authorize();

        $organizationId = $this->workos->provision($company);
        $previous = $this->context->get();

        try {
            $this->context->set($company);
            $before = ['workos_organization_id' => $company->workos_organization_id];
            $company->forceFill(['workos_organization_id' => $organizationId])->save();
            $this->audit->log('platform.company_workos_provisioned', $company, $before, [
                'workos_organization_id' => $organizationId,
            ], ['platform_account_id' => $this->access->authorize()->id]);
        } finally {
            $previous === null ? $this->context->clear() : $this->context->set($previous);
        }

        return $company->fresh();
    }
}
