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
    /**
     * Per-request memo of each user's role assignments (with role.permissions),
     * keyed by "tenantId|userId". AccessService is bound as a singleton, so a
     * list endpoint that checks access per row (e.g. the attendance records
     * resource) loads a user's grants once instead of once per row. Scope
     * filtering is then done in memory. Cleared when the tenant context changes.
     *
     * @var array<string, Collection<int, RoleAssignment>>
     */
    private array $assignmentCache = [];

    private ?string $cacheTenantId = null;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Drop the per-request assignment memo. Called after a role assignment is
     * written or revoked so a subsequent check in the same request reflects the
     * change rather than a stale snapshot.
     */
    public function flush(): void
    {
        $this->assignmentCache = [];
        $this->cacheTenantId = null;
    }

    /**
     * The user's role assignments in the active tenant (cached per request),
     * eager-loading role.permissions.
     *
     * @return Collection<int, RoleAssignment>
     */
    private function assignmentsFor(User $user): Collection
    {
        if (! $this->context->hasTenant()) {
            return collect();
        }

        $tenantId = (string) $this->context->tenantId();
        // A tenant switch within the process invalidates the memo.
        if ($this->cacheTenantId !== $tenantId) {
            $this->assignmentCache = [];
            $this->cacheTenantId = $tenantId;
        }

        return $this->assignmentCache[$user->getKey()] ??= RoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->with('role.permissions')
            ->get();
    }

    /**
     * Effective permission keys for a user in the active tenant, in effect for
     * the given target scope (defaults to company-wide).
     */
    public function permissionsFor(User $user, string $scopeType = 'company', ?string $scopeId = null): Collection
    {
        return $this->assignmentsFor($user)
            ->filter(function (RoleAssignment $a) use ($scopeType, $scopeId) {
                if ($a->scope_type === 'company') {
                    return true; // company grants are always in effect
                }

                // scope_id is a char(26) column, so PostgreSQL pads shorter
                // values with trailing spaces and ignores them on comparison.
                // The in-memory filter must replicate that CHAR semantics, or a
                // sub-ULID scope key (only ever seen in tests) would mismatch.
                return $scopeType !== 'company'
                    && $a->scope_type === $scopeType
                    && rtrim((string) $a->scope_id) === rtrim((string) $scopeId);
            })
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
        return $this->assignmentsFor($user)
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
        return $this->assignmentsFor($user)
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
        return $this->assignmentsFor($user)
            ->map(fn (RoleAssignment $a) => $a->role?->slug)
            ->filter()
            ->unique()
            ->values();
    }
}
