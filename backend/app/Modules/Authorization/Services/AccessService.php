<?php

namespace App\Modules\Authorization\Services;

use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Collection;

/**
 * Central authorization service (ADR-015). Resolves a user's effective
 * permissions within the ACTIVE tenant and an organizational scope, and answers
 * permission checks. This is the backend authority — the UI never authorizes.
 *
 * Scope rules (Sprint 0):
 *  - A company-scope assignment applies everywhere in the tenant.
 *  - A branch/department/team-scope assignment applies only to that exact
 *    scope. (Hierarchy between org units is a later sprint.)
 */
class AccessService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Effective permission keys for a user in the active tenant, in effect for
     * the given target scope (defaults to company-wide).
     */
    public function permissionsFor(User $user, string $scopeType = 'company', ?string $scopeId = null): Collection
    {
        if (! $this->context->hasTenant()) {
            return collect();
        }

        $assignments = RoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->where(function ($q) use ($scopeType, $scopeId) {
                // Company-scope grants are always in effect.
                $q->where('scope_type', 'company');
                // Plus grants that match the specific target scope.
                if ($scopeType !== 'company') {
                    $q->orWhere(function ($q2) use ($scopeType, $scopeId) {
                        $q2->where('scope_type', $scopeType)->where('scope_id', $scopeId);
                    });
                }
            })
            ->with('role.permissions')
            ->get();

        return $assignments
            ->flatMap(fn (RoleAssignment $a) => $a->role?->permissions ?? collect())
            ->pluck('key')
            ->unique()
            ->values();
    }

    public function has(User $user, string $permission, string $scopeType = 'company', ?string $scopeId = null): bool
    {
        return $this->permissionsFor($user, $scopeType, $scopeId)->contains($permission);
    }

    /**
     * All (scope_type, scope_id) grants under which the user holds the given
     * permission in the active tenant. Used to scope list queries and row-level
     * access to real Branch/Department/Team entities (ADR-015).
     *
     * @return Collection<int, array{scope_type:string, scope_id:?string}>
     */
    public function scopeGrantsFor(User $user, string $permission): Collection
    {
        if (! $this->context->hasTenant()) {
            return collect();
        }

        return RoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->with('role.permissions')
            ->get()
            ->filter(fn (RoleAssignment $a) => ($a->role?->permissions ?? collect())
                ->pluck('key')->contains($permission))
            ->map(fn (RoleAssignment $a) => [
                'scope_type' => $a->scope_type,
                'scope_id' => $a->scope_id,
            ])
            ->values();
    }

    /** True if the user holds the permission at ANY scope (route-level gate). */
    public function hasAtAnyScope(User $user, string $permission): bool
    {
        return $this->scopeGrantsFor($user, $permission)->isNotEmpty();
    }

    /**
     * Union of all permission keys the user holds across ALL scopes. Intended
     * for UI hints (nav visibility) only — never for authorization.
     */
    public function allPermissions(User $user): Collection
    {
        if (! $this->context->hasTenant()) {
            return collect();
        }

        return RoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->with('role.permissions')
            ->get()
            ->flatMap(fn (RoleAssignment $a) => $a->role?->permissions ?? collect())
            ->pluck('key')
            ->unique()
            ->values();
    }

    /** True if the user holds the permission company-wide (unscoped access). */
    public function hasCompanyWide(User $user, string $permission): bool
    {
        return $this->scopeGrantsFor($user, $permission)
            ->contains(fn (array $g) => $g['scope_type'] === 'company');
    }

    /** Roles held by a user in the active tenant (for display). */
    public function roleSlugsFor(User $user): Collection
    {
        if (! $this->context->hasTenant()) {
            return collect();
        }

        return RoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->with('role')
            ->get()
            ->map(fn (RoleAssignment $a) => $a->role?->slug)
            ->filter()
            ->unique()
            ->values();
    }
}
