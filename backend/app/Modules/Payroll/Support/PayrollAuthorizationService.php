<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Authorization\Services\AccessService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Models\PayrollSetting;
use App\Modules\Tenancy\Services\TenantContext;

/**
 * The single authority for intra-tenant payroll access (§25, Corrections W/X).
 *
 * Payroll MANAGEMENT authority is COMPANY-level only in V1: a branch/department/
 * team-scoped payroll grant must NOT expose salary, so every management/view
 * capability requires a company-wide grant. Failing that, financial surfaces are
 * treated as invisible (scope-safe 404) rather than confirming their existence.
 * RLS guards the tenant boundary; this service guards financial privacy inside
 * the tenant. Employee self-service (`payroll.view_own`) is a separate path
 * resolved via the actor's linked Employee. Role names are never authorization
 * primitives — only real RBAC grants + this service.
 */
class PayrollAuthorizationService
{
    public function __construct(
        private readonly AccessService $access,
        private readonly TenantContext $context,
    ) {}

    /** Company-wide grant check for a payroll permission (no branch/dept/team). */
    public function has(User $user, string $permission): bool
    {
        return $this->access->hasCompanyWide($user, $permission);
    }

    /**
     * Enforce company-level payroll authority. A user lacking the company-wide
     * grant gets a scope-safe 404 — financial data never confirms its existence
     * to a branch/department/team-scoped grant holder.
     */
    public function authorize(User $user, string $permission): void
    {
        abort_unless($this->has($user, $permission), 404);
    }

    /** The Employee linked to this user in the current tenant, if any. */
    public function actorEmployeeId(User $user): ?string
    {
        $id = Employee::query()->where('user_id', $user->getKey())->value('id');

        return $id === null ? null : (string) $id;
    }

    /** Whether the tenant permits an actor to manage their own payroll data. */
    public function selfPayrollAllowed(): bool
    {
        $tenantId = $this->context->tenantId();
        if ($tenantId === null) {
            return false;
        }

        return (bool) PayrollSetting::query()->where('tenant_id', $tenantId)->value('allow_self_payroll');
    }

    /**
     * Block an actor from managing the payroll data of the Employee they are
     * linked to, unless the tenant enables allow_self_payroll (D10, Correction W).
     * Applies through the real User→Employee mapping, never role names.
     */
    public function assertNotSelfManagement(User $actor, string $employeeId): void
    {
        if ($this->selfPayrollAllowed()) {
            return;
        }
        if ($this->actorEmployeeId($actor) === (string) $employeeId) {
            abort(403, __('payroll.self_payroll_forbidden'));
        }
    }
}
