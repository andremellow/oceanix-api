<?php

namespace App\Actions\ControlPlane;

use App\Models\Company;
use App\Models\PersonAccountLink;
use App\Models\User;

class MapExistingAccountPerson
{
    /** Called within ApplyProductOperation's company lock and receipt transaction. */
    public function handle(Company $company, array $input): array
    {
        $payload = $input['payload'];
        $identity = $payload['workos_user_id'] ?? null;
        $invitation = $payload['invitation_id'] ?? null;
        abort_unless($identity || $invitation, 409, 'mapping_required');
        $query = User::where('company_id', $company->id);
        $identity ? $query->where('workos_user_id', $identity) : $query->where('workos_invitation_id', $invitation)->whereNull('workos_user_id');
        $matches = $query->lockForUpdate()->get();
        abort_unless($matches->count() === 1, 409, 'mapping_required');
        $person = $matches->sole();
        abort_unless(! isset($payload['local_person_id']) || (string) $person->id === (string) $payload['local_person_id'], 409, 'identity_conflict');
        if ($identity && $invitation) {
            abort_unless($person->workos_invitation_id === $invitation, 409, 'identity_conflict');
        }
        $link = PersonAccountLink::where('company_id', $company->id)->where(function ($q) use ($person, $input) {
            $q->where('user_id', $person->id)->orWhere('company_member_id', $input['subject_id']);
        })->first();
        if ($link) {
            abort_unless($link->user_id === $person->id && $link->account_company_uuid === $input['account_company_uuid'] && $link->company_member_id === $input['subject_id'] && $link->shared_person_id === $payload['shared_person_id'], 409, 'identity_conflict');
        } else {
            $link = PersonAccountLink::create(['company_id' => $company->id, 'user_id' => $person->id, 'account_company_uuid' => $input['account_company_uuid'], 'company_member_id' => $input['subject_id'], 'shared_person_id' => $payload['shared_person_id'], 'provenance' => ['operation_id' => $input['operation_id'], 'kind' => 'person.map-existing']]);
        }

        return ['local_person_id' => (string) $person->id, 'shared_person_id' => $link->shared_person_id, 'company_member_id' => $link->company_member_id, 'mapping_complete' => true];
    }
}
