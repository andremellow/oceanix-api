<?php

namespace App\Enums;

enum RequirementStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Retired = 'retired';

    public function label(): string
    {
        return __(match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Retired => 'Retired',
        });
    }

    /** Only active requirements materialize assignments. */
    public function materializes(): bool
    {
        return $this === self::Active;
    }

    public function pillModifier(): string
    {
        return match ($this) {
            self::Active => 'status-pill--positive',
            self::Paused => 'status-pill--warning',
            self::Draft => 'status-pill--neutral',
            self::Retired => 'status-pill--neutral',
        };
    }
}
