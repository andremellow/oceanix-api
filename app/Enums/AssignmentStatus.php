<?php

namespace App\Enums;

enum AssignmentStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';
    case Waived = 'waived';

    public function label(): string
    {
        return __(match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Overdue => 'Overdue',
            self::Cancelled => 'Cancelled',
            self::Waived => 'Waived',
        });
    }

    /** Statuses that still demand action from the employee. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::InProgress, self::Failed, self::Overdue], true);
    }

    /** Statuses that satisfy the compliance obligation. */
    public function isSatisfied(): bool
    {
        return in_array($this, [self::Completed, self::Waived], true);
    }

    public function pillModifier(): string
    {
        return match ($this) {
            self::Completed, self::Waived => 'status-pill--positive',
            self::InProgress, self::Pending => 'status-pill--accent',
            self::Overdue => 'status-pill--negative',
            self::Failed => 'status-pill--warning',
            self::Cancelled => 'status-pill--neutral',
        };
    }

    /** @return list<self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isOpen()));
    }
}
