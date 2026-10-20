<?php

namespace App\Data;

use Illuminate\Support\Facades\Validator;

final readonly class AccountInvitationObservation
{
    public function __construct(public array $data)
    {
        abort_unless(is_int($data['local_person_id'] ?? null) && is_int($data['generation'] ?? null) && is_int($data['observation_sequence'] ?? null), 422);
        Validator::make($data, [
            'local_person_id' => ['required', 'integer', 'min:1'],
            'shared_person_id' => ['required', 'uuid'], 'company_member_id' => ['required', 'uuid'],
            'workos_user_id' => ['present', 'nullable', 'regex:/^user_[A-Za-z0-9_]+$/D'],
            'generation' => ['required', 'integer', 'min:0'], 'observation_sequence' => ['required', 'integer', 'min:1'],
            'verified_at' => ['required', 'date'], 'accepted_history_at' => ['present', 'nullable', 'date'],
            'invitation' => ['required', 'array:id,state,created_at,expires_at,accepted_at,revoked_at,accepted_user_id'],
            'invitation.id' => ['present', 'nullable', 'string', 'max:255'],
            'invitation.state' => ['required', 'in:none,pending,accepted,expired,revoked'],
            'invitation.created_at' => ['nullable', 'date'], 'invitation.expires_at' => ['nullable', 'date'],
            'invitation.accepted_at' => ['nullable', 'date'], 'invitation.revoked_at' => ['nullable', 'date'],
            'invitation.accepted_user_id' => ['nullable', 'regex:/^user_[A-Za-z0-9_]+$/D'],
        ])->validate();
        abort_unless(($data['invitation']['state'] === 'none') === ($data['invitation']['id'] === null), 422);
        abort_unless(array_diff(array_keys($data), ['local_person_id', 'shared_person_id', 'company_member_id', 'workos_user_id', 'generation', 'observation_sequence', 'verified_at', 'accepted_history_at', 'invitation', 'membership_evidence']) === [], 422);
    }
}
