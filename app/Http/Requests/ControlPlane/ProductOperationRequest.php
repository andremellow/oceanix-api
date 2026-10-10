<?php

namespace App\Http\Requests\ControlPlane;

use Illuminate\Foundation\Http\FormRequest;

class ProductOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('provisioning_principal') !== null && $this->header('Idempotency-Key') === $this->route('operation_uuid') && $this->input('operation_id') === $this->route('operation_uuid');
    }

    public function rules(): array
    {
        return [
            'protocol_version' => ['required', 'integer', 'in:2'], 'operation_id' => ['required', 'uuid'], 'kind' => ['required', 'in:company.provision,company.access,person.provision,person.access'],
            'product' => ['required', 'in:compliance'], 'account_company_uuid' => ['required', 'uuid'], 'workos_organization_id' => ['required', 'string', 'max:255'],
            'subject_id' => ['required', 'string', 'max:255'], 'resource_sequence' => ['required', 'integer', 'min:1'], 'actor_workos_user_id' => ['required', 'string', 'regex:/^user_[A-Za-z0-9_]+$/D'],
            'payload.name' => ['required_if:kind,company.provision', 'string', 'max:255'],
            'payload.email' => ['sometimes', 'email', 'max:255'],
            'payload.slug' => ['required_if:kind,company.provision', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'payload' => ['required', 'array'], 'payload.enabled' => ['required', 'boolean'],
            'payload.local_person_id' => ['required_if:kind,person.access', 'nullable', 'integer', 'min:1'],
            'payload.shared_person_id' => ['required_if:kind,person.provision,person.access', 'uuid'],
            'payload.workos_user_id' => ['sometimes', 'nullable', 'string', 'regex:/^user_[A-Za-z0-9_]+$/D'],
        ];
    }
}
