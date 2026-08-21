<?php

namespace App\Actions\Platform;

use App\Models\Company;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\PlatformAccess;
use App\Tenancy\TenantContext;
use InvalidArgumentException;

class ChangeCompanyStatus
{
    public function __construct(
        private readonly PlatformAccess $access,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Company $company, string $status): Company
    {
        $account = $this->access->authorize();

        if (! in_array($status, ['active', 'suspended'], true)) {
            throw new InvalidArgumentException('Unsupported company status.');
        }

        $previous = $this->context->get();

        try {
            $this->context->set($company);
            $before = $company->status;
            $company->forceFill(['status' => $status])->save();
            $this->audit->log('platform.company_status_changed', $company,
                ['status' => $before],
                ['status' => $status],
                ['platform_account_id' => $account->id],
            );
        } finally {
            $previous === null ? $this->context->clear() : $this->context->set($previous);
        }

        return $company->fresh();
    }
}
