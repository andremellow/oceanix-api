<?php

namespace App\Actions\ControlPlane;

use App\Models\AccountCompanyBinding;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyAccessReceipt;
use App\Models\ServicePrincipal;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class ChangeCompanyComplianceAccess
{
    public function __construct(private TenantContext $context) {}

    public function handle(ServicePrincipal $principal, array $input): CompanyAccessReceipt
    {
        $payload = [];
        foreach (['account_company_uuid', 'operation_id', 'workos_organization_id', 'compliance_company_public_id', 'enabled', 'version', 'actor_workos_user_id', 'correlation_id'] as $key) {
            $payload[$key] = $input[$key];
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($principal, $payload, $hash) {
            $binding = AccountCompanyBinding::where('account_company_uuid', $payload['account_company_uuid'])->first();
            abort_unless($binding, 409, 'Company identity conflicts with the binding.');
            $company = Company::whereKey($binding->compliance_company_id)->lockForUpdate()->firstOrFail();
            $current = ServicePrincipal::findOrFail($principal->id);
            abort_unless($current->active && $current->product === 'compliance' && $current->environment === app()->environment(), 403, 'Access denied.');
            abort_unless($binding->workos_organization_id === $payload['workos_organization_id'] && $company->workos_organization_id === $payload['workos_organization_id'] && $company->public_id === $payload['compliance_company_public_id'], 409, 'Company identity conflicts with the binding.');
            $receipt = CompanyAccessReceipt::where('operation_id', $payload['operation_id'])->first();
            if ($receipt) {
                abort_unless($receipt->company_id === $company->id && hash_equals($receipt->payload_hash, $hash), 409, 'Operation identity conflicts.');

                return $receipt;
            }
            abort_unless($payload['version'] === $company->compliance_access_version + 1, 409, 'Access version conflicts.');
            $company->update(['compliance_access_enabled' => $payload['enabled'], 'compliance_access_version' => $payload['version']]);
            $response = ['account_company_uuid' => $payload['account_company_uuid'], 'workos_organization_id' => $payload['workos_organization_id'], 'compliance_company_public_id' => $company->public_id, 'operation_id' => $payload['operation_id'], 'enabled' => $payload['enabled'], 'version' => $payload['version'], 'outcome' => 'applied'];
            $receipt = CompanyAccessReceipt::create(['company_id' => $company->id, 'operation_id' => $payload['operation_id'], 'sequence' => $payload['version'], 'service_principal_id' => $principal->id, 'payload_hash' => $hash, 'request' => $payload, 'response' => $response, 'response_status' => 200]);
            $previous = $this->context->get();
            try {
                $this->context->set($company);
                AuditLog::create(['actor_id' => null, 'action' => 'account.compliance_access_changed', 'auditable_type' => Company::class, 'auditable_id' => $company->id, 'metadata' => ['service_principal_id' => $principal->id, 'actor_workos_user_id' => $payload['actor_workos_user_id'], 'operation_id' => $payload['operation_id'], 'version' => $payload['version'], 'enabled' => $payload['enabled']], 'ip_address' => null]);
            } finally {
                $previous ? $this->context->set($previous) : $this->context->clear();
            }

            return $receipt;
        }, 3);
    }
}
