<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\Settings\ApplicationSettings;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyCompany
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ApplicationSettings $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->route('company');

        if (is_string($company)) {
            $company = Company::query()->where('slug', $company)->first();
        }

        if (! $company instanceof Company) {
            $companyId = $request->session()->get('company_id') ?? $request->user()?->company_id;
            $company = is_numeric($companyId) ? Company::query()->find($companyId) : null;
        }

        if ($company === null) {
            abort_if($request->routeIs('tenant.login', 'auth.local', 'auth.workos.*'), 404);

            return $next($request);
        }

        abort_if($company->status !== 'active', 404);

        $this->context->set($company);
        $request->session()->put('company_id', $company->getKey());
        $this->settings->apply();

        return $next($request);
    }
}
