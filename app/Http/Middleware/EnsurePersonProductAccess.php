<?php

namespace App\Http\Middleware;

use App\Services\People\PersonProductAccess;
use Closure;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

class EnsurePersonProductAccess
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->routeIs('livewire.update') || $request->route()?->getActionName() === HandleRequests::class.'@handleUpdate'
            || $request->routeIs('platform.*', 'login', 'tenant.login', 'logout', 'locale.update', 'certificates.verify') || $request->is('preview/courses/*')) {
            return $next($request);
        }
        if ($person = $request->user()) {
            abort_unless(app(PersonProductAccess::class)->allows($person), 403, __('Your access to this product has been removed.'));
        }

        return $next($request);
    }
}
