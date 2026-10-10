<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountRequestOperation extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'company_id', 'user_id', 'actor_id', 'kind', 'actor_workos_user_id', 'payload', 'payload_hash', 'state', 'dispatched_at', 'receipt', 'safe_error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'receipt' => 'array', 'dispatched_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record) {
            if ($record->isDirty(['id', 'company_id', 'user_id', 'actor_id', 'kind', 'actor_workos_user_id', 'payload', 'payload_hash'])) {
                throw new LogicException('Account linkage and operation evidence are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Operational evidence cannot be deleted.'));
    }
}
