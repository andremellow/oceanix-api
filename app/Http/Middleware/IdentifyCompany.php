<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyCompany extends \Oceanix\Platform\Http\Middleware\IdentifyCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        // Existing Compliance sessions may carry only the tenant-scoped login. Resolve
        // that identity before the SDK clears tenant context for global platform routes.
        if ($request->routeIs('platform.*') && ! $request->session()->has('platform_account_id')) {
            $companyId = $request->session()->get('company_id');
            $company = is_numeric($companyId) ? Company::query()->find($companyId) : null;
            $context = app(TenantContext::class);

            try {
                if ($company !== null) {
                    $context->set($company);
                    $account = $request->user()?->account;
                    if ($account?->is_platform_admin && $account->status === 'active') {
                        $request->session()->put('platform_account_id', $account->getKey());
                    }
                }
            } finally {
                $context->clear();
            }
        }

        return parent::handle($request, $next);
    }
}
