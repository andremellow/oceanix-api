<?php

namespace App\Data;

use App\Enums\WorkosInvitationState;
use Carbon\CarbonImmutable;
use RuntimeException;

final readonly class WorkosInvitationSnapshot
{
    public function __construct(public string $id, public string $email, public string $organizationId, public WorkosInvitationState $state, public CarbonImmutable $createdAt, public ?CarbonImmutable $acceptedAt, public ?CarbonImmutable $expiresAt, public ?CarbonImmutable $revokedAt, public ?string $acceptedUserId) {}

    public static function from(array $data, string $email, string $organization): self
    {
        $id = $data['id'] ?? null;
        $remoteEmail = $data['email'] ?? null;
        $state = WorkosInvitationState::tryFrom($data['state'] ?? '');
        if (! is_string($id) || $id === '' || ! is_string($remoteEmail) || strtolower(trim($remoteEmail)) !== strtolower(trim($email)) || ($data['organization_id'] ?? null) !== $organization || ! in_array($state, [WorkosInvitationState::Pending, WorkosInvitationState::Accepted, WorkosInvitationState::Expired, WorkosInvitationState::Revoked], true)) {
            throw new RuntimeException('invitation_unavailable');
        }
        try {
            $date = fn ($key) => isset($data[$key]) && $data[$key] !== '' ? CarbonImmutable::parse($data[$key])->utc() : null;
            $created = $date('created_at');
            if (! $created) {
                throw new RuntimeException;
            }

            return new self($id, strtolower(trim($remoteEmail)), $organization, $state, $created, $date('accepted_at'), $date('expires_at'), $date('revoked_at'), isset($data['accepted_user_id']) && is_string($data['accepted_user_id']) ? $data['accepted_user_id'] : null);
        } catch (\Throwable) {
            throw new RuntimeException('invitation_unavailable');
        }
    }

    public function attributes(): array
    {
        return ['workos_invitation_id' => $this->id, 'invitation_state' => $this->state, 'invitation_accepted_at' => $this->acceptedAt, 'invitation_expires_at' => $this->expiresAt, 'invitation_revoked_at' => $this->revokedAt];
    }
}
