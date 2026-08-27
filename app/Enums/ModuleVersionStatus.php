<?php

namespace App\Enums;

enum ModuleVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
    case Active = 'active';
    case Archived = 'archived';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
