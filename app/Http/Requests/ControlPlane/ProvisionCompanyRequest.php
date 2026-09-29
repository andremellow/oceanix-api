<?php

namespace App\Http\Requests\ControlPlane;

use App\Models\ServicePrincipal;
use Illuminate\Foundation\Http\FormRequest;

class ProvisionCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('provisioning_principal') instanceof ServicePrincipal;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['account_company_uuid' => $this->route('account_company_uuid'), 'operation_id' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'account_company_uuid' => ['required', 'uuid'], 'operation_id' => ['required', 'uuid'],
            'workos_organization_id' => ['required', 'string', 'max:255', 'regex:/^org_[A-Za-z0-9_]+$/D'],
            'name' => ['required', 'string', 'max:255'], 'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'actor_workos_user_id' => ['required', 'string', 'max:255', 'regex:/^user_[A-Za-z0-9_]+$/D'], 'correlation_id' => ['required', 'uuid'],
        ];
    }
}
