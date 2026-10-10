<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PersonAccountLink extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'user_id', 'account_company_uuid', 'shared_person_id', 'company_member_id', 'provenance'];

    protected function casts(): array
    {
        return ['provenance' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record) {
            if ($record->isDirty(['id', 'company_id', 'user_id', 'account_company_uuid', 'shared_person_id', 'company_member_id', 'provenance'])) {
                throw new LogicException('Account linkage and operation evidence are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Operational evidence cannot be deleted.'));
    }
}
