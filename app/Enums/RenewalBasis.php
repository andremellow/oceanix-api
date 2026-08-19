<?php

namespace App\Enums;

enum RenewalBasis: string
{
    /** The next cycle starts from the moment the previous occurrence was completed. */
    case FromCompletion = 'from_completion';

    /** The next cycle preserves the originally scheduled calendar. */
    case FromDueDate = 'from_due_date';

    public function label(): string
    {
        return __(match ($this) {
            self::FromCompletion => 'From completion date',
            self::FromDueDate => 'From due date',
        });
    }
}
