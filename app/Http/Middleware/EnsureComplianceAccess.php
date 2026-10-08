<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\Tenancy\CompanyComplianceAccess;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response;

class EnsureComplianceAccess
{
    public function __construct(private CompanyComplianceAccess $access, private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Only the actual transport route defers to checksum-verified persistent middleware.
        // The cloned original request used by Livewire has its original route and path.
        if ($request->routeIs('livewire.update') || ($request->route()?->getActionName() === HandleRequests::class.'@handleUpdate')) {
            return $next($request);
        }
        $targeted = $request->routeIs('platform.companies.enter', 'platform.companies.courses.show');
        if (! $targeted && ($request->routeIs('platform.*', 'login', 'auth.workos.platform.redirect', 'logout', 'locale.update', 'certificates.verify') || $request->is('preview/courses/*') || ($request->routeIs('auth.workos.callback') && $request->session()->get('workos_login_mode') === 'platform'))) {
            return $next($request);
        }
        $routeCompany = $request->route('company');
        if ($routeCompany instanceof Company) {
            $company = $routeCompany;
        } elseif (is_string($routeCompany)) {
            $company = $targeted ? Company::find($routeCompany) : Company::where('slug', $routeCompany)->first();
        } else {
            $company = $this->context->get();
        }
        if ($company || $targeted || $request->is('c/*') || $request->routeIs('tenant.login', 'auth.local', 'auth.workos.redirect') || $request->routeIs('legacy.*')) {
            $this->access->assertEnabled($company);
        }

        return $next($request);
    }
}
