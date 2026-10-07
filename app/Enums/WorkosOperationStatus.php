<?php

namespace App\Enums;

enum WorkosOperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Sending = 'sending';
    case Completed = 'completed';
    case Succeeded = 'succeeded';
    case Skipped = 'skipped';
    case PartialFailure = 'partial_failure';
    case Failed = 'failed';
    case DeliveryUnconfirmed = 'delivery_unconfirmed';

    public function label(): string
    {
        return __(match ($this) {
            self::Queued => 'Waiting to start',self::Running => 'Running',self::Sending => 'Sending',self::Completed => 'Completed',self::Succeeded => 'Succeeded',self::Skipped => 'Skipped',self::PartialFailure => 'Partial failure',self::Failed => 'Failed',self::DeliveryUnconfirmed => 'Delivery unconfirmed'
        });
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Queued, self::Running, self::Sending], true);
    }
}
