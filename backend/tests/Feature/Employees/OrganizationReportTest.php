<?php

namespace Tests\Feature\Employees;

use App\Modules\Employees\Models\Employee;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\TeamMembership;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8A organization/workforce reports: permission matrix, org-scope
 * confinement, tenant isolation, aggregation correctness, turnover source, date
 * bounds, and aggregate privacy (no sensitive HR field is ever returned).
 */
class OrganizationReportTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function emp(Tenant $tenant, array $attrs): Employee
    {
        return $this->withinTenant($tenant, fn () => Employee::factory()->create($attrs));
    }

    private function seedWorkforce(Tenant $tenant): Branch
    {
        $branch = $this->makeBranch($tenant);
        $other = $this->makeBranch($tenant);
        // 3 active in $branch, 1 terminated in $branch, 1 active in $other.
        $this->emp($tenant, ['branch_id' => $branch->id, 'employment_status' => 'active', 'hire_date' => '2026-02-10']);
        $this->emp($tenant, ['branch_id' => $branch->id, 'employment_status' => 'active', 'hire_date' => '2026-03-05']);
        $this->emp($tenant, ['branch_id' => $branch->id, 'employment_status' => 'active', 'hire_date' => '2025-01-01']);
        $this->emp($tenant, ['branch_id' => $branch->id, 'employment_status' => 'terminated', 'hire_date' => '2024-01-01', 'termination_date' => '2026-04-20']);
        $this->emp($tenant, ['branch_id' => $other->id, 'employment_status' => 'active', 'hire_date' => '2026-02-01']);

        return $branch;
    }

    public function test_summary_counts_and_status_breakdown_company_wide(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedWorkforce($tenant);
        $admin = $this->memberWithRole($tenant, 'admin');

        $res = $this->actingAs($admin)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/employees/reports/summary')->assertOk();

        $res->assertJsonPath('data.total', 5)
            ->assertJsonPath('data.active', 4)
            ->assertJsonPath('data.inactive', 1)
            ->assertJsonPath('meta.timezone', fn ($v) => is_string($v) && $v !== '');

        $byStatus = collect($res->json('data.by_employment_status'))->pluck('count', 'key');
        $this->assertSame(4, (int) $byStatus['active']);
        $this->assertSame(1, (int) $byStatus['terminated']);
    }

    public function test_scope_confines_population_to_granted_branch(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $branch = $this->seedWorkforce($tenant);
        // HR Manager granted only at the branch scope sees only that branch (4 rows).
        $scopedHr = $this->memberWithRole($tenant, 'hr-manager', 'branch', (string) $branch->id);

        $res = $this->actingAs($scopedHr)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/employees/reports/summary')->assertOk();

        $res->assertJsonPath('data.total', 4)->assertJsonPath('data.active', 3);
    }

    public function test_scope_confines_population_to_granted_department(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $deptA = $this->makeDepartment($tenant);
        $deptB = $this->makeDepartment($tenant);
        $this->emp($tenant, ['department_id' => $deptA->id, 'employment_status' => 'active']);
        $this->emp($tenant, ['department_id' => $deptA->id, 'employment_status' => 'active']);
        $this->emp($tenant, ['department_id' => $deptB->id, 'employment_status' => 'active']);

        $scopedHr = $this->memberWithRole($tenant, 'hr-manager', 'department', (string) $deptA->id);

        $res = $this->actingAs($scopedHr)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/employees/reports/summary')->assertOk();

        // Only department A's two employees; department B never contributes.
        $res->assertJsonPath('data.total', 2);
        $deptKeys = collect($res->json('data.by_department'))->pluck('key');
        $this->assertTrue($deptKeys->contains((string) $deptA->id));
        $this->assertFalse($deptKeys->contains((string) $deptB->id));
    }

    public function test_scope_confines_population_to_granted_team(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $teamA = $this->makeTeam($tenant);
        $inTeam = $this->emp($tenant, ['employment_status' => 'active']);
        $this->emp($tenant, ['employment_status' => 'active']); // not in team A
        $this->withinTenant($tenant, fn () => TeamMembership::create(['team_id' => $teamA->id, 'employee_id' => $inTeam->id]));

        $scopedHr = $this->memberWithRole($tenant, 'hr-manager', 'team', (string) $teamA->id);

        $res = $this->actingAs($scopedHr)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/employees/reports/summary')->assertOk();

        // Team scope IS supported by EmployeeScopeResolver: only the team member counts.
        $res->assertJsonPath('data.total', 1);
    }

    public function test_cross_tenant_employees_never_counted(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        $this->seedWorkforce($tenantA);
        [$ownerB, $tenantB] = $this->createCompanyWithOwner();
        $this->seedWorkforce($tenantB);

        $adminA = $this->memberWithRole($tenantA, 'admin');
        $this->actingAs($adminA)->withHeaders($this->tenantHeaders($tenantA))
            ->getJson('/api/employees/reports/summary')->assertOk()
            ->assertJsonPath('data.total', 5); // only tenant A's five
    }

    public function test_turnover_uses_authoritative_employee_dates(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedWorkforce($tenant);
        $admin = $this->memberWithRole($tenant, 'admin');

        $res = $this->actingAs($admin)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/employees/reports/turnover?from=2026-01-01&to=2026-12-31')->assertOk();

        // Joiners: three hired in 2026 (Feb 10, Mar 5, Feb 1). Leavers: one (Apr 20).
        $res->assertJsonPath('data.joiners_total', 3)
            ->assertJsonPath('data.leavers_total', 1)
            ->assertJsonPath('data.source', 'employees.hire_date / employees.termination_date');
    }

    public function test_turnover_rejects_window_over_one_year(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $admin = $this->memberWithRole($tenant, 'admin');

        $this->actingAs($admin)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/employees/reports/turnover?from=2024-01-01&to=2026-01-02')
            ->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_summary_exposes_no_sensitive_fields(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->emp($tenant, [
            'employment_status' => 'active', 'hire_date' => '2026-02-01',
            'work_phone' => '0790000000', 'mobile_phone' => '0791111111',
            'address_line' => 'Secret Street 1', 'date_of_birth' => '1990-01-01',
            'nationality' => 'JO', 'personal_email' => 'private@example.com',
        ]);
        $admin = $this->memberWithRole($tenant, 'admin');

        $flat = json_encode($this->actingAs($admin)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/employees/reports/summary')->assertOk()->json());

        foreach (['0790000000', '0791111111', 'Secret Street 1', '1990-01-01', 'private@example.com', 'date_of_birth', 'national', 'salary', 'compensation'] as $needle) {
            $this->assertStringNotContainsString($needle, $flat, "summary must not expose {$needle}");
        }
    }

    /** @return array<int, array{0:string}> */
    public static function deniedRoles(): array
    {
        return [['accountant'], ['department-manager'], ['team-leader'], ['employee']];
    }

    #[DataProvider('deniedRoles')]
    public function test_roles_without_permission_are_denied(string $role): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $user = $this->memberWithRole($tenant, $role);

        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/employees/reports/summary')->assertStatus(403);
    }

    /** @return array<int, array{0:string}> */
    public static function allowedRoles(): array
    {
        return [['owner'], ['admin'], ['hr-manager']];
    }

    #[DataProvider('allowedRoles')]
    public function test_roles_with_permission_are_allowed(string $role): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $user = $role === 'owner' ? $owner : $this->memberWithRole($tenant, $role);

        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/employees/reports/summary')->assertOk();
    }
}
