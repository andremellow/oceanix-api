<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProductOperationReceipt extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'service_principal_id', 'operation_id', 'subject_id', 'resource_sequence', 'payload_hash', 'request', 'response'];

    protected function casts(): array
    {
        return ['resource_sequence' => 'integer', 'request' => 'array', 'response' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record) {
            if ($record->isDirty(['id', 'company_id', 'service_principal_id', 'operation_id', 'subject_id', 'resource_sequence', 'payload_hash', 'request', 'response'])) {
                throw new LogicException('Account linkage and operation evidence are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Operational evidence cannot be deleted.'));
    }
}
