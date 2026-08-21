<?php

namespace App\Actions\People;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Workos\WorkosInvitationService;

class SendWorkosInvitation
{
    public function __construct(
        private readonly WorkosInvitationService $workos,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $person): User
    {
        $invitationId = $this->workos->send($person);

        $person->forceFill([
            'workos_invitation_id' => $invitationId,
            'invitation_sent_at' => now(),
        ])->save();

        $this->audit->log('person.workos_invitation_sent', $person, after: [
            'workos_invitation_id' => $invitationId,
            'organization_id' => $person->company->workos_organization_id,
        ]);

        return $person->fresh();
    }
}
