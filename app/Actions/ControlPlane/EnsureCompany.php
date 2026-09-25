<?php

namespace App\Actions\ControlPlane;

use App\Models\AccountCompanyBinding;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ProvisioningReceipt;
use App\Models\ServicePrincipal;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class EnsureCompany
{
    public function __construct(private TenantContext $context) {}

    public function handle(ServicePrincipal $principal, array $input): ProvisioningReceipt
    {
        $payload = [
            'account_company_uuid' => strtolower($input['account_company_uuid']), 'workos_organization_id' => $input['workos_organization_id'],
            'name' => trim($input['name']), 'slug' => $input['slug'], 'actor_workos_user_id' => $input['actor_workos_user_id'], 'correlation_id' => strtolower($input['correlation_id']),
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $operationId = strtolower($input['operation_id']);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($principal, $payload, $hash, $operationId) {
                    // A principal lock serializes absent receipt claims for its keys. Unique
                    // tenant/binding constraints protect competing principals as well.
                    $current = ServicePrincipal::whereKey($principal->id)->lockForUpdate()->firstOrFail();
                    abort_unless($current->active && $current->product === 'compliance' && $current->environment === app()->environment(), 403, 'Provisioning access denied.');
                    $receipt = ProvisioningReceipt::where('service_principal_id', $principal->id)->where('operation_id', $operationId)->first();
                    if ($receipt) {
                        abort_unless(hash_equals($receipt->payload_hash, $hash), 409, 'The operation key is already bound to different input.');

                        return $receipt;
                    }
                    $binding = AccountCompanyBinding::where('account_company_uuid', $payload['account_company_uuid'])->lockForUpdate()->first();
                    if ($binding) {
                        abort_unless($binding->workos_organization_id === $payload['workos_organization_id'], 409, 'Company identity conflicts with an existing binding.');
                    }
                    $company = Company::where('workos_organization_id', $payload['workos_organization_id'])->lockForUpdate()->first();
                    $created = false;
                    if ($company) {
                        $otherBinding = AccountCompanyBinding::where('compliance_company_id', $company->id)->first();
                        abort_if($otherBinding && $otherBinding->account_company_uuid !== $payload['account_company_uuid'], 409, 'Company identity conflicts with an existing binding.');
                        abort_if($binding && $binding->compliance_company_id !== $company->id, 409, 'Company identity conflicts with an existing binding.');
                    } else {
                        abort_if($binding || Company::where('slug', $payload['slug'])->exists(), 409, 'Company identity or slug conflicts with an existing record.');
                        $company = Company::create(['name' => $payload['name'], 'slug' => $payload['slug'], 'workos_organization_id' => $payload['workos_organization_id'], 'status' => 'active']);
                        $created = true;
                    }
                    $previous = $this->context->get();
                    try {
                        $this->context->set($company);
                        if ($created) {
                            (new PermissionSeeder)->run();
                            (new RoleSeeder)->run();
                        }
                        if (! $binding) {
                            AccountCompanyBinding::create(['account_company_uuid' => $payload['account_company_uuid'], 'compliance_company_id' => $company->id, 'workos_organization_id' => $payload['workos_organization_id']]);
                            AuditLog::create(['actor_id' => null, 'action' => 'account.company_provisioned', 'auditable_type' => Company::class, 'auditable_id' => $company->id, 'metadata' => ['service_principal_id' => $principal->id, 'actor_workos_user_id' => $payload['actor_workos_user_id'], 'correlation_id' => $payload['correlation_id'], 'operation_id' => $operationId, 'outcome' => $created ? 'created' : 'existing'], 'ip_address' => null]);
                        }
                    } finally {
                        $previous ? $this->context->set($previous) : $this->context->clear();
                    }

                    return ProvisioningReceipt::create(['service_principal_id' => $principal->id, 'operation_id' => $operationId, 'payload_hash' => $hash, 'response_status' => $created ? 201 : 200, 'response' => ['account_company_uuid' => $payload['account_company_uuid'], 'compliance_company_public_id' => $company->public_id, 'workos_organization_id' => $payload['workos_organization_id'], 'outcome' => $created ? 'created' : 'existing', 'operation_id' => $operationId]]);
                }, 3);
            } catch (QueryException $error) {
                if (! in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                    throw $error;
                }
                if ($attempt === 2) {
                    abort(409, 'Company identity conflicts with an existing record.');
                }
            }
        }
        abort(503, 'Provisioning could not be confirmed.');
    }
}
