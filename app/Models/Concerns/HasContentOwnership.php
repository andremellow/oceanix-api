<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait HasContentOwnership
{
    public static function bootHasContentOwnership(): void
    {
        static::saving(function (self $model): void {
            if (! (bool) $model->getAttribute('is_shared') && $model->getAttribute('company_id') === null) {
                $company = app(TenantContext::class)->get()
                    ?? throw new LogicException('Cannot create company-owned content without a selected company.');

                $model->setAttribute('company_id', $company->getKey());
            }

            $companyId = $model->getAttribute('company_id');
            $isShared = (bool) $model->getAttribute('is_shared');

            if (($isShared && $companyId !== null) || (! $isShared && $companyId === null)) {
                throw new LogicException('Content must be either shared without a company or company-owned and not shared.');
            }
        });
    }

    public function isShared(): bool
    {
        return (bool) $this->getAttribute('is_shared');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeShared(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('company_id'))
            ->where($query->qualifyColumn('is_shared'), true);
    }

    public function scopeCompanyOwned(Builder $query, int|Company $company): void
    {
        $query->where($query->qualifyColumn('company_id'), $company instanceof Company ? $company->getKey() : $company)
            ->where($query->qualifyColumn('is_shared'), false);
    }
}
