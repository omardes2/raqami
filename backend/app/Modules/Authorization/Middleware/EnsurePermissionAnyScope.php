<?php

namespace App\Modules\Authorization\Middleware;

use App\Modules\Authorization\Services\AccessService;
use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate for scoped resources: passes if the user holds the permission at
 * ANY organizational scope (company/branch/department/team). Row- and
 * query-level scoping is then enforced by the resource's scope resolver, so a
 * branch/department/team manager is admitted but still limited to their scope.
 */
class EnsurePermissionAnyScope
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

        if (! $this->access->hasAtAnyScope($user, $permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
