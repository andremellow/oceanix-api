<?php

namespace App\Console\Commands\Concerns;

use App\Models\Company;
use App\Services\Settings\ApplicationSettings;
use App\Tenancy\TenantContext;
use Closure;

trait RunsForEachCompany
{
    protected function forEachCompany(Closure $operation): void
    {
        $defaults = collect(array_keys(ApplicationSettings::EDITABLE))
            ->mapWithKeys(fn (string $key): array => [$key => config($key)])
            ->all();

        Company::query()->where('status', 'active')->each(function (Company $company) use ($defaults, $operation): void {
            config($defaults);
            app(TenantContext::class)->set($company);
            app(ApplicationSettings::class)->apply();
            $this->line("Company: {$company->name}");
            $operation($company);
        });

        config($defaults);
        app(TenantContext::class)->clear();
    }
}
