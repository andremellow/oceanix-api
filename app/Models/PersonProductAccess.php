<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PersonProductAccess extends Model
{
    protected $fillable = ['company_id', 'user_id', 'confirmed_enabled', 'version', 'operation_id'];

    protected function casts(): array
    {
        return ['confirmed_enabled' => 'boolean', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record) {
            if ($record->isDirty(['id', 'company_id', 'user_id'])) {
                throw new LogicException('Account linkage and operation evidence are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Operational evidence cannot be deleted.'));
    }
}
