<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum FrequencyType: string
{
    case Once = 'once';
    case Days = 'days';
    case Months = 'months';
    case Years = 'years';

    public function label(): string
    {
        return __(match ($this) {
            self::Once => 'Once',
            self::Days => 'Days',
            self::Months => 'Months',
            self::Years => 'Years',
        });
    }

    public function isRecurring(): bool
    {
        return $this !== self::Once;
    }

    /** Advance $from by one recurrence cycle. Returns null for one-off requirements. */
    public function advance(Carbon $from, ?int $value): ?Carbon
    {
        if (! $this->isRecurring() || $value === null || $value < 1) {
            return null;
        }

        return match ($this) {
            self::Days => $from->copy()->addDays($value),
            self::Months => $from->copy()->addMonths($value),
            self::Years => $from->copy()->addYears($value),
            self::Once => null,
        };
    }
}
