<?php

use App\Http\Middleware\EnsurePlatformTaskApiAccess;
use App\Http\Middleware\IdentifyCompany;
use App\Http\Middleware\PublicCoursePreview;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;
use Laravel\Nightwatch\Facades\Nightwatch;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Livewire uploads and component updates use framework-owned routes. Resolve the
        // tenant for every stateful web request so the tenant-scoped User can be restored
        // from the session before Livewire reapplies the route's auth middleware.
        $middleware->web(append: [IdentifyCompany::class, SetLocale::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, IdentifyCompany::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsurePlatformTaskApiAccess::class);
        $middleware->redirectGuestsTo(fn (): string => route('login'));
        $middleware->redirectUsersTo(fn (): string => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception) {
            if (request()->is('preview/courses/*')) {
                // Do not attach capability URLs or route parameters to exception reports.
                return false;
            }
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('preview/courses/*')) {
                return null;
            }
            Nightwatch::dontSample();
            PublicCoursePreview::locale($request);
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode()
                : ($exception instanceof TokenMismatchException ? 419 : 503);

            return PublicCoursePreview::headers(PublicCoursePreview::error($request, $status));
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
