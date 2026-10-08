<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CompanyAccessReceipt extends Model
{
    protected $fillable = ['company_id', 'operation_id', 'sequence', 'service_principal_id', 'payload_hash', 'request', 'response', 'response_status'];

    protected function casts(): array
    {
        return ['request' => 'array', 'response' => 'array', 'sequence' => 'integer', 'response_status' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Access receipts are immutable.'));
        static::deleting(fn () => throw new LogicException('Access receipts cannot be deleted.'));
    }
}
