<?php

use App\Modules\Authorization\Middleware\EnsurePermission;
use App\Modules\Localization\Middleware\SetLocale;
use App\Modules\Platform\Middleware\EnsurePlatformAdmin;
use App\Modules\Tenancy\Middleware\EnsureTenantContext;
use App\Modules\Tenancy\Middleware\ResolveTenant;
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
            'platform' => EnsurePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
