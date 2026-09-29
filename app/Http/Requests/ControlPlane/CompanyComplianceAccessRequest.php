<?php

namespace App\Http\Requests\ControlPlane;

use App\Models\ServicePrincipal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CompanyComplianceAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('provisioning_principal') instanceof ServicePrincipal;
    }

    public function rules(): array
    {
        $uuid = ['required', 'uuid', 'lowercase'];

        return ['workos_organization_id' => ['required', 'string', 'max:255', 'regex:/^org_[A-Za-z0-9_]+$/D'], 'compliance_company_public_id' => $uuid, 'enabled' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1', 'max:2147483647'], 'actor_workos_user_id' => ['required', 'string', 'max:255', 'regex:/^user_[A-Za-z0-9_]+$/D'], 'correlation_id' => $uuid];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            $keys = ['workos_organization_id', 'compliance_company_public_id', 'enabled', 'version', 'actor_workos_user_id', 'correlation_id'];
            if (! $this->isJson() || array_diff(array_keys($this->all()), $keys) || ! is_bool($this->input('enabled')) || ! is_int($this->input('version'))) {
                $v->errors()->add('command', 'Invalid access command.');
            }
            foreach ([$this->route('account_company_uuid'), $this->header('Idempotency-Key')] as $id) {
                if (! is_string($id) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id)) {
                    $v->errors()->add('identity', 'Invalid command identity.');
                }
            }
            if ($this->input('correlation_id') !== $this->header('Idempotency-Key')) {
                $v->errors()->add('correlation_id', 'Correlation must match operation.');
            }
        }];
    }

    public function command(): array
    {
        return ['account_company_uuid' => $this->route('account_company_uuid'), 'operation_id' => $this->header('Idempotency-Key'), ...$this->safe()->only(['workos_organization_id', 'compliance_company_public_id', 'enabled', 'version', 'actor_workos_user_id', 'correlation_id'])];
    }
}
