<?php

namespace App\Enums;

enum CourseStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';
    case Archived = 'archived';

    public function label(): string
    {
        return __(match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Retired => 'Retired',
            self::Archived => 'Archived',
        });
    }

    public function pillModifier(): string
    {
        return match ($this) {
            self::Active => 'status-pill--positive',
            self::Draft => 'status-pill--neutral',
            self::Retired, self::Archived => 'status-pill--warning',
        };
    }
}
