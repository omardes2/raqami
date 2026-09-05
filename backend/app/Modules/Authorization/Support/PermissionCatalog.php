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
        'employees.reports.view' => ['employees', 'View organization / employee reports (aggregate, non-sensitive)'],

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

        // --- Sprint 4: Attendance advanced (tenant scope) ---
        'attendance.holidays.view' => ['attendance', 'View holiday calendars & holidays'],
        'attendance.holidays.manage' => ['attendance', 'Manage holiday calendars, holidays & assignments'],
        'attendance.exceptions.view' => ['attendance', 'View attendance exceptions (remote/off-day/etc.)'],
        'attendance.exceptions.manage' => ['attendance', 'Create / revoke attendance exceptions'],
        'attendance.overtime.view' => ['attendance', 'View overtime approvals'],
        'attendance.overtime.review' => ['attendance', 'Approve / reject overtime'],
        'attendance.overtime.override' => ['attendance', 'Approve overtime ABOVE the server-calculated amount'],
        'attendance.anomalies.view' => ['attendance', 'View attendance anomalies'],
        'attendance.anomalies.manage' => ['attendance', 'Acknowledge / resolve / dismiss attendance anomalies'],
        'attendance.materialization.run' => ['attendance', 'Run daily attendance materialization'],

        // --- Sprint 5: Leave (tenant scope) ---
        // Employee self-service (own request/balance/history) requires an
        // authenticated, employee-linked user — NOT a permission. These keys gate
        // viewing/administering OTHER employees' leave.
        'leave.view_own' => ['leave', 'View own leave (self-service reference)'],
        'leave.request' => ['leave', 'Request own leave (self-service reference)'],
        'leave.view' => ['leave', 'View employees leave requests (within scope)'],
        'leave.manage' => ['leave', 'Administer leave requests (cancel / reassign)'],
        'leave.approve' => ['leave', 'Approve / reject leave requests (within scope)'],
        'leave.types.view' => ['leave', 'View leave types'],
        'leave.types.manage' => ['leave', 'Manage leave types'],
        'leave.policies.view' => ['leave', 'View leave policies & assignments'],
        'leave.policies.manage' => ['leave', 'Manage leave policies & assignments'],
        'leave.balances.view' => ['leave', 'View leave balances (within scope)'],
        'leave.balances.adjust' => ['leave', 'Adjust leave balances (within scope)'],
        'leave.negative_override' => ['leave', 'Approve / reserve leave INTO a negative balance'],
        'leave.attachments.view_sensitive' => ['leave', 'Access sensitive leave attachments (e.g. medical)'],
        'leave.reports.view' => ['leave', 'View leave reports & team calendar'],
        'leave.settings.manage' => ['leave', 'Manage company leave settings'],

        // Sprint 6 — Tasks & Teams.
        'tasks.view_own' => ['tasks', 'View own assigned tasks'],
        'tasks.create' => ['tasks', 'Create tasks (within scope)'],
        'tasks.view' => ['tasks', 'View tasks (within scope)'],
        'tasks.manage' => ['tasks', 'Manage tasks (within scope)'],
        'tasks.assign' => ['tasks', 'Assign tasks (within scope)'],
        'tasks.comment' => ['tasks', 'Comment on visible tasks'],
        'tasks.attach' => ['tasks', 'Attach files to visible tasks'],
        'tasks.reports.view' => ['tasks', 'View task reports & workload (within scope)'],
        'tasks.settings.manage' => ['tasks', 'Manage the tenant task status catalog'],
        'projects.view' => ['tasks', 'View projects (within scope)'],
        'projects.create' => ['tasks', 'Create projects (within scope)'],
        'projects.manage' => ['tasks', 'Manage projects & governance (within scope)'],

        // Sprint 7 — Payroll (company-level authority; sensitive financial data).
        'payroll.view_own' => ['payroll', 'View own finalized payslips'],
        'payroll.compensation.view' => ['payroll', 'View employee compensation (sensitive)'],
        'payroll.compensation.manage' => ['payroll', 'Create / change / end employee compensation'],
        'payroll.components.manage' => ['payroll', 'Manage the tenant compensation component catalog'],
        'payroll.runs.view' => ['payroll', 'View payroll periods & runs'],
        'payroll.runs.manage' => ['payroll', 'Create payroll periods & runs'],
        'payroll.calculate' => ['payroll', 'Calculate / recalculate a payroll run'],
        'payroll.adjust' => ['payroll', 'Create / edit manual payroll adjustments'],
        'payroll.approve' => ['payroll', 'Approve a calculated payroll run (four-eyes)'],
        'payroll.finalize' => ['payroll', 'Finalize a payroll run (immutable)'],
        'payroll.negative_override' => ['payroll', 'Finalize despite a negative net (with reason)'],
        'payroll.reports.view' => ['payroll', 'View payroll reports & totals'],
        'payroll.settings.manage' => ['payroll', 'Manage company payroll settings'],

        // --- Sprint 9: AI assistant (read-only, assistive) ---
        'ai.use' => ['ai', 'Use the AI assistant (read-only summaries within your own authorized scope)'],
    ];

    /** Sprint 3 + 4 attendance permission groups reused in default role mappings. */
    private const ATTENDANCE_FULL = [
        'attendance.view', 'attendance.view_location', 'attendance.manage',
        'attendance.corrections.review',
        'attendance.schedules.view', 'attendance.schedules.manage',
        'attendance.locations.manage', 'attendance.settings.manage',
        'attendance.reports.view',
        // Sprint 4
        'attendance.holidays.view', 'attendance.holidays.manage',
        'attendance.exceptions.view', 'attendance.exceptions.manage',
        'attendance.overtime.view', 'attendance.overtime.review',
        'attendance.anomalies.view', 'attendance.anomalies.manage',
        'attendance.materialization.run',
    ];

    private const ATTENDANCE_MANAGER = [
        'attendance.view', 'attendance.manage', 'attendance.corrections.review',
        'attendance.schedules.view', 'attendance.reports.view',
        // Sprint 4: managers see holidays/exceptions/anomalies and review overtime
        // for their scope, but do not manage company-wide holiday calendars.
        'attendance.holidays.view',
        'attendance.exceptions.view', 'attendance.exceptions.manage',
        'attendance.overtime.view', 'attendance.overtime.review',
        'attendance.anomalies.view', 'attendance.anomalies.manage',
    ];

    private const ATTENDANCE_VIEW = [
        'attendance.view', 'attendance.reports.view',
        'attendance.holidays.view',
    ];

    /**
     * Sprint 5 leave permission groups. leave.negative_override and
     * leave.attachments.view_sensitive are deliberately EXCLUDED from LEAVE_FULL
     * and granted explicitly (a distinct privilege, like attendance.overtime.override).
     */
    private const LEAVE_FULL = [
        'leave.view', 'leave.manage', 'leave.approve',
        'leave.types.view', 'leave.types.manage',
        'leave.policies.view', 'leave.policies.manage',
        'leave.balances.view', 'leave.balances.adjust',
        'leave.reports.view', 'leave.settings.manage',
    ];

    private const LEAVE_MANAGER = [
        'leave.view', 'leave.approve', 'leave.balances.view', 'leave.reports.view',
    ];

    private const LEAVE_VIEW = [
        'leave.view', 'leave.reports.view',
    ];

    /**
     * Sprint 6 task permission groups. Project-local authority (owner/manager
     * membership) is a bounded ACL evaluated by TaskVisibilityResolver — it is
     * NOT part of these company/scoped grants.
     */
    private const TASKS_FULL = [
        'tasks.view', 'tasks.create', 'tasks.manage', 'tasks.assign',
        'tasks.comment', 'tasks.attach', 'tasks.reports.view', 'tasks.settings.manage',
        'projects.view', 'projects.create', 'projects.manage',
    ];

    private const TASKS_MANAGER = [
        'tasks.view', 'tasks.create', 'tasks.manage', 'tasks.assign',
        'tasks.comment', 'tasks.attach', 'tasks.reports.view',
        'projects.view', 'projects.create', 'projects.manage',
    ];

    /** Employee participation: visibility comes from assignment, not a broad grant. */
    private const TASKS_PARTICIPANT = [
        'tasks.view_own', 'tasks.comment', 'tasks.attach',
    ];

    /**
     * Sprint 7 payroll permission groups. Payroll management is COMPANY-level
     * authority only (financial privacy). PAYROLL_ACCOUNTANT is the operational
     * subset: the Accountant may view compensation and run/calculate/adjust
     * payroll, but NOT change compensation, manage components, approve, finalize,
     * override negative net, or manage settings (D17).
     */
    private const PAYROLL_FULL = [
        'payroll.compensation.view', 'payroll.compensation.manage',
        'payroll.components.manage',
        'payroll.runs.view', 'payroll.runs.manage',
        'payroll.calculate', 'payroll.adjust', 'payroll.approve', 'payroll.finalize',
        'payroll.negative_override',
        'payroll.reports.view', 'payroll.settings.manage',
    ];

    private const PAYROLL_ACCOUNTANT = [
        'payroll.compensation.view',
        'payroll.runs.view', 'payroll.runs.manage',
        'payroll.calculate', 'payroll.adjust',
        'payroll.reports.view',
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
        // Sprint 8A: aggregate organization/employee reporting. Included here so it
        // reaches exactly Admin + HR Manager (both use EMPLOYEES_FULL); Owner holds
        // it via '*'. Accountant / Department Manager / Team Leader / Employee use
        // narrower explicit employee grants and therefore do NOT receive it.
        'employees.reports.view',
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
                // Overtime override is a distinct privilege above ATTENDANCE_FULL;
                // Owner holds it via '*'. HR Manager reviews but cannot override
                // unless explicitly granted a custom role that includes it.
                'attendance.overtime.override',
                ...self::LEAVE_FULL,
                // Distinct privileges above LEAVE_FULL (Owner holds via '*').
                'leave.negative_override', 'leave.attachments.view_sensitive',
                ...self::TASKS_FULL,
                ...self::PAYROLL_FULL,
                'ai.use',
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
                ...self::LEAVE_FULL,
                // HR sees sensitive (medical) attachments; not negative_override.
                'leave.attachments.view_sensitive',
                // HR does NOT own tasks/projects by default (D9) — reports only.
                'tasks.reports.view',
                'ai.use',
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
                ...self::LEAVE_MANAGER,
                // Scope-limited task administration (branch/department by assignment).
                ...self::TASKS_MANAGER,
                'ai.use',
            ],
        ],
        'team-leader' => [
            'name' => 'Team Leader',
            // Scope-limited to their team by role assignment. Leave approval is NOT
            // default (D2) — view only unless a custom role grants leave.approve.
            // Tasks: scoped view + assign within their team (D3); NO tasks.manage.
            'permissions' => [
                'user.view', 'teams.view', 'employees.view',
                ...self::ATTENDANCE_VIEW, ...self::LEAVE_VIEW,
                'tasks.view', 'tasks.assign', 'tasks.comment', 'tasks.attach', 'tasks.reports.view',
                'ai.use',
            ],
        ],
        'accountant' => [
            'name' => 'Accountant',
            // Payroll permissions are a future sprint; none are seeded now.
            'permissions' => [
                'company.view',
                ...self::ORG_VIEW,
                'employees.view', 'employee_contracts.view',
                ...self::BILLING_ACCOUNTANT,
                // Sprint 7: the Accountant is the operational payroll role (view
                // compensation, run/calculate/adjust) — but NOT manage
                // compensation, components, approve, finalize, override, settings.
                ...self::PAYROLL_ACCOUNTANT,
                'ai.use',
            ],
        ],
        'employee' => [
            'name' => 'Employee',
            // Self-service endpoints require authentication, not a permission.
            // Task participation: view own assigned + comment/attach. NO tasks.create
            // by default (grantable later); status/checklist/watch on an assigned
            // task are participation-inherent, not a permission.
            'permissions' => [...self::TASKS_PARTICIPANT, 'payroll.view_own'],
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
