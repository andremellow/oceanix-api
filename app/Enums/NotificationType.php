<?php

namespace App\Enums;

enum NotificationType: string
{
    case AssignmentCreated = 'assignment.created';
    case DueSoon = 'assignment.due_soon';
    case Overdue = 'assignment.overdue';
    case OverdueReminder = 'assignment.overdue_reminder';
    case Completed = 'assignment.completed';

    public function label(): string
    {
        return __(match ($this) {
            self::AssignmentCreated => 'New training assigned',
            self::DueSoon => 'Training due soon',
            self::Overdue => 'Training overdue',
            self::OverdueReminder => 'Training still overdue',
            self::Completed => 'Training completed',
        });
    }

    public function subject(string $course): string
    {
        return __(match ($this) {
            self::AssignmentCreated => 'New training assigned: :course',
            self::DueSoon => 'Due soon: :course',
            self::Overdue => 'Overdue: :course',
            self::OverdueReminder => 'Still overdue: :course',
            self::Completed => 'Completed: :course',
        }, ['course' => $course]);
    }

    public function isEscalation(): bool
    {
        return in_array($this, [self::Overdue, self::OverdueReminder], true);
    }
}
