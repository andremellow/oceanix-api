<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\Settings\ApplicationSettings;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class IdentifyCompany
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ApplicationSettings $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeCompany = $request->route('company');
        $company = $routeCompany;

        if (is_string($company)) {
            $company = Company::query()->where('slug', $company)->first();
        }

        if (! $company instanceof Company) {
            $companyId = $request->session()->get('company_id') ?? $request->user()?->company_id;
            $company = is_numeric($companyId) ? Company::query()->find($companyId) : null;
        }

        if ($company === null) {
            abort_if($request->routeIs('tenant.login', 'auth.local'), 404);

            return $next($request);
        }

        abort_if($company->status !== 'active', 404);

        $this->context->set($company);
        $request->session()->put('company_id', $company->getKey());
        URL::defaults(['company:slug' => $company->slug]);
        $this->settings->apply();

        if ($request->user() !== null && (int) $request->user()->company_id !== (int) $company->getKey()) {
            abort(404);
        }

        return $next($request);
    }
}
