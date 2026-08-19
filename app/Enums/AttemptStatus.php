<?php

namespace App\Enums;

enum AttemptStatus: string
{
    case InProgress = 'in_progress';
    case Passed = 'passed';
    case Failed = 'failed';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return __(match ($this) {
            self::InProgress => 'In progress',
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::Abandoned => 'Abandoned',
        });
    }

    public function isFinished(): bool
    {
        return $this !== self::InProgress;
    }
}
