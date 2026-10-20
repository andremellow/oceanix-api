<?php

namespace App\Http\Requests\ControlPlane;

use Illuminate\Foundation\Http\FormRequest;

class ProductOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('provisioning_principal') !== null && $this->header('Idempotency-Key') === $this->route('operation_uuid') && $this->input('operation_id') === $this->route('operation_uuid');
    }

    public function after(): array
    {
        return [function ($validator) {
            $allowed = match ($this->input('kind')) {
                'person.map-existing' => ['shared_person_id', 'local_person_id', 'workos_user_id', 'invitation_id'],
                'person.invitation.observed' => ['local_person_id', 'shared_person_id', 'company_member_id', 'workos_user_id', 'invitation', 'generation', 'observation_sequence', 'verified_at', 'accepted_history_at', 'membership_evidence'],
                default => null,
            };
            if ($allowed && is_array($this->input('payload')) && array_diff(array_keys($this->input('payload')), $allowed) !== []) {
                $validator->errors()->add('payload', 'Unexpected operation fields.');
            }
        }];
    }

    public function rules(): array
    {
        return [
            'protocol_version' => ['required', 'integer', 'in:2'], 'operation_id' => ['required', 'uuid'], 'kind' => ['required', 'in:company.provision,company.access,person.provision,person.access,person.map-existing,person.invitation.observed'],
            'product' => ['required', 'in:compliance'], 'account_company_uuid' => ['required', 'uuid'], 'workos_organization_id' => ['required', 'string', 'max:255'],
            'subject_id' => ['required', 'string', 'max:255'], 'resource_sequence' => ['required', 'integer', 'min:1'], 'actor_workos_user_id' => ['required', 'string', 'regex:/^user_[A-Za-z0-9_]+$/D'],
            'payload.name' => ['required_if:kind,company.provision', 'string', 'max:255'],
            'payload.email' => ['sometimes', 'email', 'max:255'],
            'payload.slug' => ['required_if:kind,company.provision', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'payload' => ['required', 'array'], 'payload.enabled' => ['required_unless:kind,person.map-existing,person.invitation.observed', 'prohibited_if:kind,person.map-existing,person.invitation.observed', 'boolean'],
            'payload.local_person_id' => ['required_if:kind,person.access', 'nullable', 'integer', 'min:1'],
            'payload.shared_person_id' => ['required_if:kind,person.provision,person.access,person.map-existing,person.invitation.observed', 'uuid'],
            'payload.invitation_id' => ['sometimes', 'nullable', 'string'],
            'payload.company_member_id' => ['required_if:kind,person.invitation.observed', 'uuid'],
            'payload.generation' => ['required_if:kind,person.invitation.observed', 'integer', 'min:0'],
            'payload.observation_sequence' => ['required_if:kind,person.invitation.observed', 'integer', 'min:1'],
            'payload.invitation' => ['required_if:kind,person.invitation.observed', 'array'],
            'payload.verified_at' => ['required_if:kind,person.invitation.observed', 'date'],
            'payload.accepted_history_at' => ['present_if:kind,person.invitation.observed', 'nullable', 'date'],
            'payload.workos_user_id' => ['sometimes', 'nullable', 'string', 'regex:/^user_[A-Za-z0-9_]+$/D'],
        ];
    }
}
