<?php

namespace App\Modules\Authorization\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\PermissionCatalog;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\TenantContext;
use InvalidArgumentException;

/** Writes role assignments (scoped) and audits them. */
class RoleAssignmentService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
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

        $assignment = RoleAssignment::query()->firstOrCreate([
            'tenant_id' => $this->context->tenantId(),
            'user_id' => $user->getKey(),
            'role_id' => $role->getKey(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);

        if ($assignment->wasRecentlyCreated) {
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

    public function revoke(RoleAssignment $assignment, mixed $actor = null): void
    {
        $meta = [
            'role_id' => $assignment->role_id,
            'user_id' => $assignment->user_id,
            'scope_type' => $assignment->scope_type,
            'scope_id' => $assignment->scope_id,
        ];

        $assignment->delete();

        $this->audit->log('role.removed', [
            'actor' => $actor,
            'subject_type' => User::class,
            'subject_id' => $meta['user_id'],
            'metadata' => $meta,
        ]);
    }
}
