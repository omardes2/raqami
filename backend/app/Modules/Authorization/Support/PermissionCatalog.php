<?php

namespace App\Modules\Authorization\Support;

/**
 * The authoritative catalog of permissions and default roles that EXIST in
 * Sprint 0. Only foundation permissions are listed — no permissions for
 * unimplemented business modules (attendance, tasks, payroll, billing, AI).
 * Those catalogs are added by their own sprints.
 */
class PermissionCatalog
{
    /** key => [module, description]. */
    public const PERMISSIONS = [
        // Company (tenant) settings foundation
        'company.view' => ['organization', 'View company profile & settings'],
        'company.update' => ['organization', 'Update company profile & settings'],

        // Users / membership foundation
        'user.view' => ['identity', 'View tenant users & memberships'],
        'user.invite' => ['identity', 'Invite users to the company'],
        'user.manage' => ['identity', 'Activate / deactivate tenant users'],

        // Roles & permissions foundation
        'role.view' => ['authorization', 'View roles & permissions'],
        'role.manage' => ['authorization', 'Create / update roles'],
        'permission.assign' => ['authorization', 'Assign roles to users'],

        // Audit foundation
        'audit.view' => ['audit', 'View the company audit log'],
    ];

    /**
     * Default system roles seeded per tenant. '*' means "all Sprint 0
     * permissions" — note this is still fully tenant-scoped: Owner does NOT
     * bypass tenant isolation, it simply holds every permission inside its own
     * tenant.
     */
    public const ROLES = [
        'owner' => [
            'name' => 'Owner',
            'permissions' => '*',
        ],
        'admin' => [
            'name' => 'Admin',
            'permissions' => [
                'company.view', 'company.update',
                'user.view', 'user.invite', 'user.manage',
                'role.view', 'role.manage', 'permission.assign',
                'audit.view',
            ],
        ],
        'hr-manager' => [
            'name' => 'HR Manager',
            'permissions' => [
                'company.view', 'user.view', 'user.invite', 'user.manage',
            ],
        ],
        'department-manager' => [
            'name' => 'Department Manager',
            'permissions' => ['company.view', 'user.view'],
        ],
        'team-leader' => [
            'name' => 'Team Leader',
            'permissions' => ['user.view'],
        ],
        'accountant' => [
            'name' => 'Accountant',
            // Payroll permissions are a future sprint; none are seeded now.
            'permissions' => ['company.view'],
        ],
        'employee' => [
            'name' => 'Employee',
            // Self-service endpoints require authentication, not a permission.
            'permissions' => [],
        ],
    ];

    /** Organizational scope types the authorization model supports (ADR-015). */
    public const SCOPE_TYPES = ['company', 'branch', 'department', 'team'];

    public static function permissionKeys(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    public static function permissionsForRole(string $slug): array
    {
        $role = self::ROLES[$slug] ?? null;
        if ($role === null) {
            return [];
        }

        return $role['permissions'] === '*'
            ? self::permissionKeys()
            : $role['permissions'];
    }
}
