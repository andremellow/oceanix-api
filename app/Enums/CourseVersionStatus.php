<?php

namespace App\Enums;

enum CourseVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
    case Discarded = 'discarded';

    public function label(): string
    {
        return __(match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Retired => 'Retired',
            self::Discarded => 'Discarded',
        });
    }

    /**
     * A published version is immutable: content changes require a new draft version.
     * See docs/product-spec.md §6.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function pillModifier(): string
    {
        return match ($this) {
            self::Published => 'status-pill--positive',
            self::Draft => 'status-pill--neutral',
            self::Retired => 'status-pill--warning',
            self::Discarded => 'status-pill--neutral',
        };
    }
}
