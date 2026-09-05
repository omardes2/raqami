<?php

namespace App\Modules\Authorization\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\PermissionCatalog;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/** Writes role assignments (scoped) and audits them. */
class RoleAssignmentService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly AccessService $access,
    ) {}

    public function assignBySlug(
        User $user,
        string $slug,
        string $scopeType = 'company',
        ?string $scopeId = null,
        mixed $actor = null,
    ): RoleAssignment {
        $role = Role::query()->where('slug', $slug)->firstOrFail();

        return $this->assign($user, $role, $scopeType, $scopeId, $actor);
    }

    public function assign(
        User $user,
        Role $role,
        string $scopeType = 'company',
        ?string $scopeId = null,
        mixed $actor = null,
    ): RoleAssignment {
        if (! in_array($scopeType, PermissionCatalog::SCOPE_TYPES, true)) {
            throw new InvalidArgumentException("Unknown scope type: {$scopeType}");
        }

        // Role-ceiling guard (Sprint 10). Only enforced for a real acting User
        // (the HTTP path); internal provisioning/onboarding passes no User actor
        // and may seed any role, including owner.
        if ($actor instanceof User) {
            $this->assertActorMayGrant($actor, $role);
        }

        $assignment = RoleAssignment::query()->firstOrCreate([
            'tenant_id' => $this->context->tenantId(),
            'user_id' => $user->getKey(),
            'role_id' => $role->getKey(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);

        if ($assignment->wasRecentlyCreated) {
            $this->access->flush(); // a new grant invalidates the per-request memo
            $this->audit->log('role.assigned', [
                'actor' => $actor,
                'subject' => $user,
                'metadata' => [
                    'role' => $role->slug,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                ],
            ]);
        }

        return $assignment;
    }

    /**
     * A non-owner actor may never grant the owner role, and may never grant a
     * role carrying a permission the actor does not themselves hold — preventing
     * vertical privilege escalation within the tenant (e.g. an admin making
     * themselves owner, or minting a role with powers beyond their own).
     */
    private function assertActorMayGrant(User $actor, Role $role): void
    {
        if ($this->access->roleSlugsFor($actor)->contains('owner')) {
            return; // owner may grant any role within the tenant
        }

        if ($role->slug === 'owner') {
            throw new AuthorizationException('Only an owner may grant the owner role.');
        }

        $actorPermissions = $this->access->allPermissions($actor)->flip();
        foreach ($role->permissions()->pluck('key') as $permission) {
            if (! $actorPermissions->has($permission)) {
                throw new AuthorizationException('You cannot grant a role with permissions beyond your own.');
            }
        }
    }

    public function revoke(RoleAssignment $assignment, mixed $actor = null): void
    {
        $meta = [
            'role_id' => $assignment->role_id,
            'user_id' => $assignment->user_id,
            'scope_type' => $assignment->scope_type,
            'scope_id' => $assignment->scope_id,
        ];

        $assignment->delete();
        $this->access->flush(); // a revoked grant invalidates the per-request memo

        $this->audit->log('role.removed', [
            'actor' => $actor,
            'subject_type' => User::class,
            'subject_id' => $meta['user_id'],
            'metadata' => $meta,
        ]);
    }
}
