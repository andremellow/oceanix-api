<?php

namespace App\Enums;

enum UserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    public function label(): string
    {
        return __(match ($this) {
            self::Invited => 'Invited',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Terminated => 'Terminated',
        });
    }

    /** Only active people are materialized into new assignments. */
    public function isEligibleForTraining(): bool
    {
        return $this === self::Active;
    }

    public function pillModifier(): string
    {
        return match ($this) {
            self::Active => 'status-pill--positive',
            self::Invited => 'status-pill--accent',
            self::Suspended => 'status-pill--warning',
            self::Terminated => 'status-pill--neutral',
        };
    }
}
