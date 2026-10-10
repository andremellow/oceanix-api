<?php

namespace App\Http\Requests\ControlPlane;

use Illuminate\Foundation\Http\FormRequest;

class ProductActorAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('provisioning_principal') !== null;
    }

    public function rules(): array
    {
        return ['protocol_version' => ['required', 'integer', 'in:2'], 'operation_id' => ['required', 'uuid'], 'kind' => ['required', 'in:person.register,person.adopt,person.access.remove,person.invite,person.invitation.reconcile'], 'product' => ['required', 'in:compliance'], 'account_company_uuid' => ['required', 'uuid'], 'workos_organization_id' => ['required', 'string'], 'subject_id' => ['required', 'string'], 'resource_sequence' => ['required', 'integer', 'min:1'], 'actor_workos_user_id' => ['required', 'string'], 'payload' => ['present', 'array'], 'payload_hash' => ['required', 'string', 'size:64']];
    }
}
