<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Http\Request;

/**
 * Resolves which tenant a request acts as.
 *
 * Sprint 0 primarily resolves from the AUTHENTICATED USER's active membership.
 * A user who belongs to multiple companies may pick one via the `X-Tenant-Id`
 * header — but only a tenant they are actually a member of is ever accepted.
 *
 * The resolver is deliberately structured so subdomain / custom-domain
 * resolution can be added later (resolveFromRequest) without changing callers.
 * No wildcard DNS is required in Sprint 0.
 */
class TenantResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function resolveForUser(User $user, Request $request): ?Tenant
    {
        // Let the current user read their own memberships (RLS own-membership
        // policy) before any tenant context is chosen.
        $this->context->setUser($user->getKey());

        $requested = $request->header('X-Tenant-Id');

        $membershipQuery = TenantMembership::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('user_id', $user->getKey())
            ->where('status', 'active');

        if (! empty($requested)) {
            $membership = (clone $membershipQuery)->where('tenant_id', $requested)->first();
            // If they asked for a tenant they don't belong to, resolve nothing
            // (do NOT silently fall back — that would mask an access attempt).
            if ($membership === null) {
                return null;
            }
        } else {
            $membership = $membershipQuery->orderBy('created_at')->first();
        }

        if ($membership === null) {
            return null;
        }

        return Tenant::query()->find($membership->tenant_id);
    }

    /**
     * Placeholder for future host-based resolution (subdomain/custom domain).
     * Not used in Sprint 0.
     */
    public function resolveFromRequest(Request $request): ?Tenant
    {
        return null;
    }
}
