<?php

namespace Tests\Feature\Payroll;

use App\Modules\Authorization\Services\RoleAssignmentService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Payroll management authority is COMPANY-level only (§25, Corrections W/X): a
 * branch/department/team-scoped payroll grant must never expose salary, so it
 * gets a scope-safe 404 rather than a 403 that would confirm the record exists.
 * RLS guards the tenant boundary; this layer guards financial privacy inside it.
 */
class PayrollAuthorizationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, fn () => app(EmployeeService::class)
            ->create(['first_name' => 'Z', 'last_name' => 'Z', 'employment_status' => 'active']));
    }

    public function test_default_role_payroll_access_matrix(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant);
        $url = "/api/payroll/compensations/{$emp->getKey()}";

        // Owner ('*') and Admin (PAYROLL_FULL, company-wide) can view compensation.
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))->getJson($url)->assertOk();
        $admin = $this->memberWithRole($tenant, 'admin');
        $this->actingAs($admin)->withHeaders($this->tenantHeaders($tenant))->getJson($url)->assertOk();

        // Accountant (PAYROLL_ACCOUNTANT) can VIEW compensation but NOT manage it.
        $accountant = $this->memberWithRole($tenant, 'accountant');
        $this->actingAs($accountant)->withHeaders($this->tenantHeaders($tenant))->getJson($url)->assertOk();
        $this->actingAs($accountant)->withHeaders($this->tenantHeaders($tenant))
            ->postJson($url, ['currency' => 'USD', 'base_amount_minor' => 100000, 'effective_from' => '2026-01-01'])
            ->assertStatus(403);

        // HR Manager, Department Manager, Team Leader and Employee have no
        // compensation grant at all → 403 at the permission gate.
        foreach (['hr-manager', 'department-manager', 'team-leader', 'employee'] as $role) {
            $user = $this->memberWithRole($tenant, $role);
            $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))->getJson($url)->assertStatus(403);
        }
    }

    public function test_settings_require_settings_manage_not_merely_payroll_view(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        // Owner manages settings; Accountant (no settings.manage) cannot.
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))->getJson('/api/payroll/settings')->assertOk();
        $accountant = $this->memberWithRole($tenant, 'accountant');
        $this->actingAs($accountant)->withHeaders($this->tenantHeaders($tenant))->getJson('/api/payroll/settings')->assertStatus(403);
    }

    public function test_branch_scoped_payroll_grant_gets_scope_safe_404_not_salary(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant);
        $branch = $this->makeBranch($tenant);

        // A user holding the full admin grant, but only at BRANCH scope.
        $user = $this->makeUser();
        $this->withinTenant($tenant, function () use ($user, $branch) {
            TenantMembership::create(['user_id' => $user->id, 'status' => 'active']);
            app(RoleAssignmentService::class)->assignBySlug($user, 'admin', 'branch', (string) $branch->getKey());
        });

        // The permission gate passes (a grant exists at some scope), but the
        // company-level authority check denies with a scope-safe 404 — the
        // response never distinguishes "no access" from "no such record".
        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/compensations/{$emp->getKey()}")
            ->assertStatus(404);
    }

    public function test_company_wide_custom_grant_is_honored(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant);

        // Same admin role, but assigned COMPANY-wide → access is granted.
        $user = $this->makeUser();
        $this->withinTenant($tenant, function () use ($user) {
            TenantMembership::create(['user_id' => $user->id, 'status' => 'active']);
            app(RoleAssignmentService::class)->assignBySlug($user, 'admin', 'company', null);
        });

        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/compensations/{$emp->getKey()}")
            ->assertOk();
    }

    public function test_out_of_tenant_employee_id_is_a_scope_safe_404(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        [, $tenantB] = $this->createCompanyWithOwner();
        $foreign = $this->employee($tenantB);

        // Owner A has full company-wide payroll authority, but employee B is
        // invisible under tenant A's RLS scope → 404, never a cross-tenant read.
        $this->actingAs($ownerA)->withHeaders($this->tenantHeaders($tenantA))
            ->getJson("/api/payroll/compensations/{$foreign->getKey()}")
            ->assertStatus(404);

        // A syntactically valid but non-existent ULID is likewise 404.
        $this->actingAs($ownerA)->withHeaders($this->tenantHeaders($tenantA))
            ->getJson('/api/payroll/compensations/'.strtolower((string) Str::ulid()))
            ->assertStatus(404);
    }
}
