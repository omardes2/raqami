<?php

namespace App\Modules\Tenancy\Middleware;

use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Aborts requests that require an active tenant but have none. */
class EnsureTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->hasTenant()) {
            abort(409, 'No active tenant. Complete company onboarding or select a company.');
        }

        return $next($request);
    }
}
