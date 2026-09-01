<?php

namespace Tests\Feature\Employees;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class EmployeeTransferAndScopeTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_transfer_updates_employee_and_records_history_and_audit(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $branchA = $this->makeBranch($tenant, ['code' => 'A']);
        $branchB = $this->makeBranch($tenant, ['code' => 'B']);
        $employee = $this->makeEmployee($tenant, ['employee_number' => 'EMP-T', 'branch_id' => $branchA->id]);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/employees/{$employee->id}/transfer", ['branch_id' => $branchB->id])
            ->assertOk()->assertJsonPath('branch_id', $branchB->id);

        $history = $this->withinTenant($tenant, fn () => $employee->historyEvents()->pluck('event_type')->all());
        $this->assertContains('branch_changed', $history);
        $audit = $this->withinTenant($tenant, fn () => AuditLog::query()->pluck('action')->all());
        $this->assertContains('employee.transferred', $audit);
    }

    public function test_self_management_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->makeEmployee($tenant, ['employee_number' => 'EMP-SELF']);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/employees/{$employee->id}/transfer", ['direct_manager_employee_id' => $employee->id])
            ->assertStatus(422);
    }

    public function test_branch_scoped_manager_only_sees_their_branch(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $branchA = $this->makeBranch($tenant, ['code' => 'A']);
        $branchB = $this->makeBranch($tenant, ['code' => 'B']);
        $empA = $this->makeEmployee($tenant, ['employee_number' => 'EMP-A', 'branch_id' => $branchA->id]);
        $empB = $this->makeEmployee($tenant, ['employee_number' => 'EMP-B', 'branch_id' => $branchB->id]);

        // A manager scoped to branch A.
        $manager = $this->memberWithRole($tenant, 'department-manager', 'branch', $branchA->id);

        // List returns ONLY branch A employees.
        $list = $this->actingAs($manager)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/employees')->assertOk()->json('data');
        $ids = collect($list)->pluck('id')->all();
        $this->assertContains($empA->id, $ids);
        $this->assertNotContains($empB->id, $ids);

        // Direct access to a branch-B employee is a scope-safe 404 (IDOR blocked).
        $this->actingAs($manager)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$empB->id}")->assertNotFound();

        // But branch-A employee is accessible.
        $this->actingAs($manager)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$empA->id}")->assertOk();
    }

    public function test_department_scoped_manager_sees_subtree(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $ops = $this->makeDepartment($tenant, ['name' => 'Ops', 'code' => 'OPS']);
        $logistics = $this->makeDepartment($tenant, ['name' => 'Logistics', 'code' => 'LOG', 'parent_department_id' => $ops->id]);
        $other = $this->makeDepartment($tenant, ['name' => 'Sales', 'code' => 'SAL']);

        $inSubtree = $this->makeEmployee($tenant, ['employee_number' => 'EMP-SUB', 'department_id' => $logistics->id]);
        $outside = $this->makeEmployee($tenant, ['employee_number' => 'EMP-OUT', 'department_id' => $other->id]);

        $manager = $this->memberWithRole($tenant, 'department-manager', 'department', $ops->id);

        // Sees the child-department employee (subtree), not the unrelated one.
        $this->actingAs($manager)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$inSubtree->id}")->assertOk();
        $this->actingAs($manager)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$outside->id}")->assertNotFound();
    }

    public function test_owner_has_company_wide_access(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $branchA = $this->makeBranch($tenant, ['code' => 'A']);
        $branchB = $this->makeBranch($tenant, ['code' => 'B']);
        $this->makeEmployee($tenant, ['employee_number' => 'EMP-A', 'branch_id' => $branchA->id]);
        $this->makeEmployee($tenant, ['employee_number' => 'EMP-B', 'branch_id' => $branchB->id]);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/employees')->assertOk()->assertJsonCount(2, 'data');
    }
}
