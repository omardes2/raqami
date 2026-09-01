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

        // --- Sprint 1: Organization ---
        'branches.view' => ['organization', 'View branches'],
        'branches.create' => ['organization', 'Create branches'],
        'branches.update' => ['organization', 'Update branches'],
        'branches.archive' => ['organization', 'Archive branches'],

        'departments.view' => ['organization', 'View departments'],
        'departments.create' => ['organization', 'Create departments'],
        'departments.update' => ['organization', 'Update departments'],
        'departments.archive' => ['organization', 'Archive departments'],

        'teams.view' => ['organization', 'View teams'],
        'teams.create' => ['organization', 'Create teams'],
        'teams.update' => ['organization', 'Update teams'],
        'teams.archive' => ['organization', 'Archive teams'],

        'job_titles.view' => ['organization', 'View job titles'],
        'job_titles.create' => ['organization', 'Create job titles'],
        'job_titles.update' => ['organization', 'Update job titles'],
        'job_titles.archive' => ['organization', 'Archive job titles'],

        // --- Sprint 1: Employees ---
        'employees.view' => ['employees', 'View employees (within scope)'],
        'employees.create' => ['employees', 'Create employees'],
        'employees.update' => ['employees', 'Update employees'],
        'employees.archive' => ['employees', 'Archive / terminate employees'],
        'employees.transfer' => ['employees', 'Transfer / change employee organization'],
        'employees.link_user' => ['employees', 'Link / unlink an employee to a user account'],
        'employees.view_sensitive' => ['employees', 'View sensitive employee data'],

        // --- Sprint 1: Employee documents ---
        'employee_documents.view' => ['employees', 'View employee documents'],
        'employee_documents.upload' => ['employees', 'Upload employee documents'],
        'employee_documents.delete' => ['employees', 'Delete employee documents'],

        // --- Sprint 1: Employee contracts ---
        'employee_contracts.view' => ['employees', 'View employee contracts'],
        'employee_contracts.create' => ['employees', 'Create employee contracts'],
        'employee_contracts.update' => ['employees', 'Update employee contracts'],
        'employee_contracts.archive' => ['employees', 'Archive employee contracts'],

        // --- Sprint 2: Billing & subscriptions (tenant scope) ---
        // Platform (Super Admin) billing management is a SEPARATE identity/guard,
        // never part of tenant RBAC (see Platform module).
        'billing.view' => ['billing', 'View billing overview'],
        'billing.manage' => ['billing', 'Manage billing (profile, change plan)'],
        'billing.subscription.view' => ['billing', 'View the subscription'],
        'billing.subscription.change' => ['billing', 'Change plan / cancel / resume'],
        'billing.invoices.view' => ['billing', 'View invoices'],
        'billing.payments.view' => ['billing', 'View payments'],
        'billing.bank_transfer.submit' => ['billing', 'Submit bank-transfer proof for review'],

        // --- Sprint 3: Attendance (tenant scope) ---
        // Employee self-service (own check-in/out, own attendance, own correction
        // request) requires an authenticated, employee-linked user — NOT a
        // permission. These keys gate viewing/administering OTHER employees.
        'attendance.view' => ['attendance', 'View attendance records (within scope)'],
        'attendance.view_location' => ['attendance', 'View precise GPS coordinates on attendance (sensitive)'],
        'attendance.manage' => ['attendance', 'Record / manually enter attendance for employees'],
        'attendance.corrections.review' => ['attendance', 'Approve / reject attendance correction requests'],
        'attendance.schedules.view' => ['attendance', 'View work schedules & assignments'],
        'attendance.schedules.manage' => ['attendance', 'Create / update work schedules & assignments'],
        'attendance.locations.manage' => ['attendance', 'Manage attendance geofence locations'],
        'attendance.settings.manage' => ['attendance', 'Manage company attendance settings'],
        'attendance.reports.view' => ['attendance', 'View attendance reports'],
    ];

    /** Sprint 3 attendance permission groups reused in default role mappings. */
    private const ATTENDANCE_FULL = [
        'attendance.view', 'attendance.view_location', 'attendance.manage',
        'attendance.corrections.review',
        'attendance.schedules.view', 'attendance.schedules.manage',
        'attendance.locations.manage', 'attendance.settings.manage',
        'attendance.reports.view',
    ];

    private const ATTENDANCE_MANAGER = [
        'attendance.view', 'attendance.manage', 'attendance.corrections.review',
        'attendance.schedules.view', 'attendance.reports.view',
    ];

    private const ATTENDANCE_VIEW = [
        'attendance.view', 'attendance.reports.view',
    ];

    /** Sprint 2 billing permission groups reused in default role mappings. */
    private const BILLING_FULL = [
        'billing.view', 'billing.manage',
        'billing.subscription.view', 'billing.subscription.change',
        'billing.invoices.view', 'billing.payments.view',
        'billing.bank_transfer.submit',
    ];

    private const BILLING_ACCOUNTANT = [
        'billing.view', 'billing.subscription.view',
        'billing.invoices.view', 'billing.payments.view',
        'billing.bank_transfer.submit',
    ];

    /** Sprint 1 permission groups reused in default role mappings. */
    private const ORG_FULL = [
        'branches.view', 'branches.create', 'branches.update', 'branches.archive',
        'departments.view', 'departments.create', 'departments.update', 'departments.archive',
        'teams.view', 'teams.create', 'teams.update', 'teams.archive',
        'job_titles.view', 'job_titles.create', 'job_titles.update', 'job_titles.archive',
    ];

    private const ORG_VIEW = [
        'branches.view', 'departments.view', 'teams.view', 'job_titles.view',
    ];

    private const EMPLOYEES_FULL = [
        'employees.view', 'employees.create', 'employees.update', 'employees.archive',
        'employees.transfer', 'employees.link_user', 'employees.view_sensitive',
        'employee_documents.view', 'employee_documents.upload', 'employee_documents.delete',
        'employee_contracts.view', 'employee_contracts.create', 'employee_contracts.update', 'employee_contracts.archive',
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
                ...self::ORG_FULL,
                ...self::EMPLOYEES_FULL,
                ...self::BILLING_FULL,
                ...self::ATTENDANCE_FULL,
            ],
        ],
        'hr-manager' => [
            'name' => 'HR Manager',
            'permissions' => [
                'company.view', 'user.view', 'user.invite', 'user.manage',
                ...self::ORG_VIEW,
                'departments.create', 'departments.update',
                'teams.create', 'teams.update',
                'job_titles.create', 'job_titles.update', 'job_titles.archive',
                ...self::EMPLOYEES_FULL,
                ...self::ATTENDANCE_FULL,
            ],
        ],
        'department-manager' => [
            'name' => 'Department Manager',
            // Scope-limited (branch/department) by role assignment; no sensitive.
            'permissions' => [
                'company.view', 'user.view',
                ...self::ORG_VIEW,
                'employees.view', 'employees.update',
                'employee_documents.view', 'employee_contracts.view',
                ...self::ATTENDANCE_MANAGER,
            ],
        ],
        'team-leader' => [
            'name' => 'Team Leader',
            // Scope-limited to their team by role assignment.
            'permissions' => ['user.view', 'teams.view', 'employees.view', ...self::ATTENDANCE_VIEW],
        ],
        'accountant' => [
            'name' => 'Accountant',
            // Payroll permissions are a future sprint; none are seeded now.
            'permissions' => [
                'company.view',
                ...self::ORG_VIEW,
                'employees.view', 'employee_contracts.view',
                ...self::BILLING_ACCOUNTANT,
            ],
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
