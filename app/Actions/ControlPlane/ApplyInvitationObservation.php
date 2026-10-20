<?php

namespace App\Actions\ControlPlane;

use App\Data\AccountInvitationObservation;
use App\Enums\WorkosInvitationState;
use App\Models\Company;
use App\Models\PersonAccountLink;
use App\Models\User;
use Carbon\CarbonImmutable;

class ApplyInvitationObservation
{
    /** The receiver owns the surrounding company lock, projection and receipt transaction. */
    public function handle(Company $company, array $input): array
    {
        $data = (new AccountInvitationObservation($input['payload']))->data;
        $person = User::where('company_id', $company->id)->whereKey($data['local_person_id'])->lockForUpdate()->firstOrFail();
        $link = PersonAccountLink::where('company_id', $company->id)->where('user_id', $person->id)->first();
        abort_unless($link && $link->account_company_uuid === $input['account_company_uuid'] && $link->company_member_id === $input['subject_id'] && $link->company_member_id === $data['company_member_id'] && $link->shared_person_id === $data['shared_person_id'] && $person->workos_user_id === $data['workos_user_id'], 409, 'identity_conflict');
        $acceptedIdentity = $data['invitation']['accepted_user_id'] ?? null;
        abort_unless(! $acceptedIdentity || ! $person->workos_user_id || $acceptedIdentity === $person->workos_user_id, 409, 'identity_conflict');
        $pair = [$data['generation'], $data['observation_sequence']];
        $current = [(int) $person->invitation_generation, (int) $person->invitation_observation_sequence];
        $hash = ApplyProductOperation::hash($data);
        $result = 'superseded';
        if ($pair === $current) {
            abort_unless($person->invitation_observation_hash && hash_equals($person->invitation_observation_hash, $hash), 409, 'observation_conflict');
        } elseif ($pair > $current) {
            abort_unless($data['generation'] > $current[0] || $person->workos_invitation_id === $data['invitation']['id'], 409, 'invitation_conflict');
            $updates = ['workos_invitation_id' => $data['invitation']['id'], 'invitation_state' => $data['invitation']['state'] === 'none' ? WorkosInvitationState::NotInvited : $data['invitation']['state'], 'invitation_generation' => $data['generation'], 'invitation_observation_sequence' => $data['observation_sequence'], 'invitation_observation_hash' => $hash, 'invitation_verified_at' => $data['verified_at'], 'invitation_check_error' => null];
            foreach (['created_at' => 'invitation_sent_at', 'expires_at' => 'invitation_expires_at', 'accepted_at' => 'invitation_accepted_at', 'revoked_at' => 'invitation_revoked_at'] as $source => $target) {
                $updates[$target] = $data['invitation'][$source] ?? null;
            }
            $history = $data['accepted_history_at'];
            if ($history && (! $person->invitation_accepted_history_at || CarbonImmutable::parse($history)->gt($person->invitation_accepted_history_at))) {
                $updates['invitation_accepted_history_at'] = $history;
            }
            $person->forceFill($updates)->save();
            $result = 'applied';
        }

        return ['local_person_id' => (string) $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id, 'result' => $result, 'generation' => (int) $person->invitation_generation, 'observation_sequence' => (int) $person->invitation_observation_sequence];
    }
}
