<?php

namespace App\Enums;

enum CourseStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';

    public function label(): string
    {
        return __(match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Retired => 'Retired',
        });
    }

    public function pillModifier(): string
    {
        return match ($this) {
            self::Active => 'status-pill--positive',
            self::Draft => 'status-pill--neutral',
            self::Retired => 'status-pill--warning',
        };
    }
}
