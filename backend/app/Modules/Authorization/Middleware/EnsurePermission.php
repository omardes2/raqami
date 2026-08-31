<?php

namespace App\Modules\Authorization\Middleware;

use App\Modules\Authorization\Services\AccessService;
use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backend permission gate (route middleware `permission:key`). Frontend
 * visibility is NEVER authorization — every sensitive endpoint passes here.
 */
class EnsurePermission
{
    public function __construct(
        private readonly AccessService $access,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->context->hasTenant()) {
            abort(403, 'Forbidden.');
        }

        if (! $this->access->has($user, $permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
