<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            $company = app(TenantContext::class)->get();

            $company === null
                ? $builder->whereRaw('1 = 0')
                : $builder->where($builder->qualifyColumn('company_id'), $company->getKey());
        });

        static::creating(function (self $model): void {
            $current = app(TenantContext::class)->get();

            if ($model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', $current?->getKey()
                    ?? throw new LogicException('Cannot create tenant-owned data without a selected company.'));
            }

            if ($current !== null && (int) $model->getAttribute('company_id') !== (int) $current->getKey()) {
                throw new LogicException('Cannot create data for a different company.');
            }
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
