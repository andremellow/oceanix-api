<?php

namespace App\Enums;

enum SharedContentPropagationItemStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
