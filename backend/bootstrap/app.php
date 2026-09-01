<?php

use App\Modules\Authorization\Middleware\EnsurePermission;
use App\Modules\Authorization\Middleware\EnsurePermissionAnyScope;
use App\Modules\Localization\Middleware\SetLocale;
use App\Modules\Platform\Middleware\EnsurePlatformAdmin;
use App\Modules\Tenancy\Middleware\EnsureTenantContext;
use App\Modules\Tenancy\Middleware\ResolveTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First-party SPA (Sanctum) cookie/session authentication for /api/*.
        $middleware->statefulApi();

        // Locale resolution for every API request (ADR-012).
        $middleware->api(append: [SetLocale::class]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.required' => EnsureTenantContext::class,
            'permission' => EnsurePermission::class,
            'permission.any' => EnsurePermissionAnyScope::class,
            'platform' => EnsurePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // API is stateless-friendly: never redirect guests to a login page;
        // always answer 401 JSON (correct for the SPA and future mobile clients).
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 401);
            }

            return null;
        });
    })->create();
