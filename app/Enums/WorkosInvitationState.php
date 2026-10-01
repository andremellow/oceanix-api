<?php

namespace App\Enums;

enum WorkosInvitationState: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case NotInvited = 'not_invited';
    case Unverified = 'unverified';

    public function label(): string
    {
        return __(match ($this) {
            self::Pending => 'Pending',self::Accepted => 'Accepted',self::Expired => 'Expired',self::Revoked => 'Revoked',self::NotInvited => 'Not invited',self::Unverified => 'Unverified'
        });
    }

    public function pillModifier(): string
    {
        return 'status-pill--'.match ($this) {
            self::Pending => 'accent',self::Accepted => 'positive',self::Expired => 'warning',self::Revoked => 'negative',default => 'neutral'
        };
    }
}
