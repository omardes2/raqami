<?php

namespace App\Modules\Tenancy\Middleware;

use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Tenancy\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant context for an authenticated request and always clears
 * it afterwards (terminate) so nothing leaks to the next request. Requests
 * without a resolvable tenant simply have no context (endpoints that require a
 * tenant use EnsureTenantContext).
 */
class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Defensive: start from a clean, closed-by-default context.
        $this->context->clear();

        $user = $request->user();
        if ($user !== null) {
            $this->context->setUser($user->getKey());

            $tenant = $this->resolver->resolveForUser($user, $request);
            if ($tenant !== null) {
                $this->context->setTenant($tenant);
            }
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }
}
