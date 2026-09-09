<?php

use App\Http\Middleware\EnsureCompanyIsActive;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\ResolveCompanyContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TransitFlow has no server-rendered login page — the SPA and the
        // API are the only clients — so an unauthenticated request never
        // redirects; it gets a 401 (JSON for `api/*`).
        $middleware->redirectGuestsTo(fn () => null);

        // On every API request: resolve the caller's company, then reject a
        // suspended/inactive one. Placed on the api group but forced ahead
        // of SubstituteBindings in the priority list, so route-model
        // binding is already company-scoped (another company's record 404s
        // at the router). Both no-op for an unauthenticated request — the
        // route's own `auth:sanctum` still returns the 401.
        $middleware->api(append: [
            ResolveCompanyContext::class,
            EnsureCompanyIsActive::class,
        ]);

        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveCompanyContext::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureCompanyIsActive::class);

        $middleware->alias([
            'company.context' => ResolveCompanyContext::class,
            'company.active' => EnsureCompanyIsActive::class,
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
