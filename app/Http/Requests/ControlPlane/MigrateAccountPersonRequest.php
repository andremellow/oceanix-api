<?php

namespace App\Http\Requests\ControlPlane;

use Illuminate\Foundation\Http\FormRequest;

class MigrateAccountPersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('provisioning_principal') !== null && $this->header('Idempotency-Key') === $this->route('operation_uuid') && $this->input('operation_id') === $this->route('operation_uuid');
    }

    public function rules(): array
    {
        return [
            'operation_id' => ['required', 'uuid'], 'preview' => ['required', 'boolean'],
            'account_company_uuid' => ['required', 'uuid'], 'workos_organization_id' => ['required', 'string', 'regex:/^org_[A-Za-z0-9_]+$/D', 'max:255'],
            'actor_workos_user_id' => ['required', 'string', 'regex:/^user_[A-Za-z0-9_]+$/D', 'max:255'],
            'shared_person_id' => ['required', 'uuid'], 'company_member_id' => ['required', 'uuid'],
            'source_key' => ['required', 'string', 'max:300'],
            'workos_user_id' => ['present', 'nullable', 'string', 'regex:/^user_[A-Za-z0-9_]+$/D', 'max:255'],
            'workos_membership_id' => ['required_with:workos_user_id', 'nullable', 'string', 'regex:/^om_[A-Za-z0-9_]+$/D', 'max:255'],
            'invitation_id' => ['required_without:workos_user_id', 'nullable', 'string', 'regex:/^invitation_[A-Za-z0-9_]+$/D', 'max:255'],
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'],
            'baseline_eligible' => ['required', 'boolean'], 'payload_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ];
    }
}
