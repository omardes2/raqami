<?php

namespace Tests\Feature\Employees;

use App\Modules\Authorization\Services\RoleAssignmentService;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\TeamMembership;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Scope-aware sensitive-field authorization (NB-1).
 *
 * employees.view_sensitive must be valid for the SPECIFIC employee being
 * serialized, respecting Company / Branch / Department / Team scopes. Holding
 * view_sensitive in one scope must never expose sensitive fields for an employee
 * in another scope — even when the viewer can otherwise see that employee via a
 * separate (non-sensitive) employees.view grant.
 */
class EmployeeSensitiveScopeTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** Assign an additional system role (at a scope) to an existing member. */
    private function grantRole(Tenant $tenant, User $user, string $slug, string $scopeType = 'company', ?string $scopeId = null): void
    {
        $this->withinTenant($tenant, fn () => app(RoleAssignmentService::class)
            ->assignBySlug($user, $slug, $scopeType, $scopeId));
    }

    // 1. Company-scoped view_sensitive -> sensitive fields visible.
    public function test_company_scoped_view_sensitive_sees_sensitive_fields(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->makeEmployee($tenant, [
            'employee_number' => 'EMP-C1',
            'personal_email' => 'company@example.com',
        ]);
        $hr = $this->memberWithRole($tenant, 'hr-manager'); // company scope

        $this->actingAs($hr)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('sensitive.personal_email', 'company@example.com');
    }

    // 2. Branch-A scoped view_sensitive + employee in Branch A -> visible.
    public function test_branch_scoped_view_sensitive_sees_in_branch_employee(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $branchA = $this->makeBranch($tenant, ['code' => 'A']);
        $empA = $this->makeEmployee($tenant, [
            'employee_number' => 'EMP-BA',
            'branch_id' => $branchA->id,
            'personal_email' => 'branch-a@example.com',
        ]);
        $hrA = $this->memberWithRole($tenant, 'hr-manager', 'branch', $branchA->id);

        $this->actingAs($hrA)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$empA->id}")
            ->assertOk()
            ->assertJsonPath('sensitive.personal_email', 'branch-a@example.com');
    }

    // 3. Branch-A scoped view_sensitive, but employee visible in Branch B via a
    //    separate non-sensitive grant -> employee visible, sensitive NOT returned.
    public function test_branch_scoped_view_sensitive_does_not_leak_across_branches(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $branchA = $this->makeBranch($tenant, ['code' => 'A']);
        $branchB = $this->makeBranch($tenant, ['code' => 'B']);
        $empA = $this->makeEmployee($tenant, [
            'employee_number' => 'EMP-A',
            'branch_id' => $branchA->id,
            'personal_email' => 'a@example.com',
        ]);
        $empB = $this->makeEmployee($tenant, [
            'employee_number' => 'EMP-B',
            'branch_id' => $branchB->id,
            'personal_email' => 'b@example.com',
        ]);

        // view_sensitive only in Branch A; plain employees.view in Branch B.
        $viewer = $this->memberWithRole($tenant, 'hr-manager', 'branch', $branchA->id);
        $this->grantRole($tenant, $viewer, 'department-manager', 'branch', $branchB->id);

        // Branch A employee: sensitive visible.
        $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$empA->id}")
            ->assertOk()
            ->assertJsonPath('sensitive.personal_email', 'a@example.com');

        // Branch B employee: visible, but sensitive block withheld.
        $responseB = $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$empB->id}")->assertOk();
        $responseB->assertJsonMissingPath('sensitive');
        $this->assertStringNotContainsString('b@example.com', $responseB->getContent());
    }

    // 4. Department-scoped view_sensitive respects the department subtree.
    public function test_department_scoped_view_sensitive_respects_subtree(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $ops = $this->makeDepartment($tenant, ['name' => 'Ops', 'code' => 'OPS']);
        $logistics = $this->makeDepartment($tenant, ['name' => 'Logistics', 'code' => 'LOG', 'parent_department_id' => $ops->id]);
        $sales = $this->makeDepartment($tenant, ['name' => 'Sales', 'code' => 'SAL']);

        $inSubtree = $this->makeEmployee($tenant, [
            'employee_number' => 'EMP-SUB', 'department_id' => $logistics->id, 'personal_email' => 'sub@example.com',
        ]);
        $outside = $this->makeEmployee($tenant, [
            'employee_number' => 'EMP-OUT', 'department_id' => $sales->id, 'personal_email' => 'out@example.com',
        ]);

        // Company-wide view (no sensitive) + view_sensitive scoped to Ops.
        $viewer = $this->memberWithRole($tenant, 'accountant'); // company employees.view, no sensitive
        $this->grantRole($tenant, $viewer, 'hr-manager', 'department', $ops->id);

        // Child-department employee (subtree): sensitive visible.
        $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$inSubtree->id}")
            ->assertOk()
            ->assertJsonPath('sensitive.personal_email', 'sub@example.com');

        // Unrelated department: visible (company view) but sensitive withheld.
        $responseOut = $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$outside->id}")->assertOk();
        $responseOut->assertJsonMissingPath('sensitive');
        $this->assertStringNotContainsString('out@example.com', $responseOut->getContent());
    }

    // 5. Team-scoped view_sensitive only exposes sensitive for team members.
    public function test_team_scoped_view_sensitive_only_for_team_members(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $team = $this->makeTeam($tenant, ['name' => 'Alpha', 'code' => 'ALPHA']);
        $member = $this->makeEmployee($tenant, [
            'employee_number' => 'EMP-TM', 'personal_email' => 'member@example.com',
        ]);
        $nonMember = $this->makeEmployee($tenant, [
            'employee_number' => 'EMP-NM', 'personal_email' => 'nonmember@example.com',
        ]);
        $this->withinTenant($tenant, fn () => TeamMembership::create([
            'team_id' => $team->id, 'employee_id' => $member->id,
        ]));

        // Company-wide view (no sensitive) + view_sensitive scoped to the team.
        $viewer = $this->memberWithRole($tenant, 'accountant');
        $this->grantRole($tenant, $viewer, 'hr-manager', 'team', $team->id);

        // Team member: sensitive visible.
        $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$member->id}")
            ->assertOk()
            ->assertJsonPath('sensitive.personal_email', 'member@example.com');

        // Non-member: visible (company view) but sensitive withheld.
        $responseNm = $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$nonMember->id}")->assertOk();
        $responseNm->assertJsonMissingPath('sensitive');
        $this->assertStringNotContainsString('nonmember@example.com', $responseNm->getContent());
    }

    // 6. Cross-tenant access remains fully blocked (scope-safe 404, no leakage).
    public function test_cross_tenant_sensitive_access_remains_blocked(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [$ownerB, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);
        $empA = $this->makeEmployee($tenantA, [
            'employee_number' => 'EMP-XA', 'personal_email' => 'secret-a@example.com',
        ]);

        $response = $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->getJson("/api/employees/{$empA->id}")->assertNotFound();
        $this->assertStringNotContainsString('secret-a@example.com', $response->getContent());
    }

    // 7. Default Owner / Admin / HR (company scope) behavior is unchanged.
    public function test_default_company_roles_still_see_sensitive_fields(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->makeEmployee($tenant, [
            'employee_number' => 'EMP-DEF', 'personal_email' => 'default@example.com',
        ]);

        $actors = [
            $owner,
            $this->memberWithRole($tenant, 'admin'),
            $this->memberWithRole($tenant, 'hr-manager'),
        ];

        foreach ($actors as $actor) {
            $this->actingAs($actor)->withHeaders($this->tenantHeaders($tenant))
                ->getJson("/api/employees/{$employee->id}")
                ->assertOk()
                ->assertJsonPath('sensitive.personal_email', 'default@example.com');
        }
    }
}
