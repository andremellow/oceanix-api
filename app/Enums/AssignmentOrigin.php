<?php

namespace App\Enums;

enum AssignmentOrigin: string
{
    case Requirement = 'requirement';
    case Manual = 'manual';
    case Mobilization = 'mobilization';
    case Job = 'job';
    case Api = 'api';

    public function label(): string
    {
        return __(match ($this) {
            self::Requirement => 'Training requirement',
            self::Manual => 'Manual assignment',
            self::Mobilization => 'Mobilization schedule',
            self::Job => 'Job import',
            self::Api => 'API import',
        });
    }
}
