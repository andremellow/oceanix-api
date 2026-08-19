<?php

namespace App\Enums;

enum VideoStatus: string
{
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return __(match ($this) {
            self::Uploading => 'Uploading',
            self::Processing => 'Processing',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
        });
    }

    public function pillModifier(): string
    {
        return match ($this) {
            self::Ready => 'status-pill--positive',
            self::Failed => 'status-pill--negative',
            default => 'status-pill--warning',
        };
    }
}
