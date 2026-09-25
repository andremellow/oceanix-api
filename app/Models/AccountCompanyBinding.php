<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountCompanyBinding extends Model
{
    protected $fillable = ['account_company_uuid', 'compliance_company_id', 'workos_organization_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Account identity binding is immutable.'));
        static::deleting(fn () => throw new LogicException('Account identity binding cannot be deleted.'));
    }
}
