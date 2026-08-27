<?php

namespace App\Enums;

enum SharedContentPropagationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithFailures = 'completed_with_failures';
}
