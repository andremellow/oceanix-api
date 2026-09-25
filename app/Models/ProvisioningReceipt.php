<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProvisioningReceipt extends Model
{
    protected $fillable = ['service_principal_id', 'operation_id', 'payload_hash', 'response', 'response_status'];

    protected function casts(): array
    {
        return ['response' => 'array', 'response_status' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Provisioning receipts are immutable.'));
        static::deleting(fn () => throw new LogicException('Provisioning receipts cannot be deleted.'));
    }
}
