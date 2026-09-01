<?php

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\JobTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_owner_can_create_and_list_branches(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/branches', ['name' => 'HQ', 'code' => 'HQ'])
            ->assertCreated()->assertJsonPath('code', 'HQ');

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/branches')->assertOk()->assertJsonPath('data.0.code', 'HQ');
    }

    public function test_branch_code_is_unique_per_tenant_but_reusable_across_tenants(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [$ownerB, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);

        $this->actingAs($ownerA)->withHeaders($this->tenantHeaders($tenantA))
            ->postJson('/api/branches', ['name' => 'HQ', 'code' => 'HQ'])->assertCreated();

        // Duplicate within the same tenant is rejected.
        $this->actingAs($ownerA)->withHeaders($this->tenantHeaders($tenantA))
            ->postJson('/api/branches', ['name' => 'HQ2', 'code' => 'HQ'])->assertStatus(422);

        // The same code is fine in a DIFFERENT tenant.
        $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->postJson('/api/branches', ['name' => 'HQ', 'code' => 'HQ'])->assertCreated();
    }

    public function test_departments_support_hierarchy(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $parent = $this->makeDepartment($tenant, ['name' => 'Operations', 'code' => 'OPS']);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/departments', ['name' => 'Logistics', 'code' => 'LOG', 'parent_department_id' => $parent->id])
            ->assertCreated()->assertJsonPath('parent_department_id', $parent->id);
    }

    public function test_department_parent_cycle_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->makeDepartment($tenant, ['name' => 'A', 'code' => 'A']);
        $b = $this->makeDepartment($tenant, ['name' => 'B', 'code' => 'B', 'parent_department_id' => $a->id]);

        // Making A a child of B would create a cycle (A -> B -> A).
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->patchJson("/api/departments/{$a->id}", ['name' => 'A', 'code' => 'A', 'parent_department_id' => $b->id])
            ->assertStatus(422);

        // And a department cannot be its own parent.
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->patchJson("/api/departments/{$a->id}", ['name' => 'A', 'code' => 'A', 'parent_department_id' => $a->id])
            ->assertStatus(422);
    }

    public function test_team_and_membership_management(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->makeEmployee($tenant, ['employee_number' => 'EMP-1']);

        $teamId = $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/teams', ['name' => 'Alpha', 'code' => 'ALPHA'])
            ->assertCreated()->json('id');

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/teams/{$teamId}/members", ['employee_id' => $employee->id])
            ->assertCreated();

        $this->assertSame(1, $this->withinTenant($tenant, fn () => $employee->teams()->count()));
    }

    public function test_job_title_archive_blocked_while_referenced(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $jt = $this->withinTenant($tenant, fn () => JobTitle::factory()->create(['title' => 'Engineer', 'code' => 'ENG']));
        $this->makeEmployee($tenant, ['employee_number' => 'EMP-9', 'job_title_id' => $jt->id]);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/job-titles/{$jt->id}/archive")->assertStatus(422);
    }

    public function test_manager_must_belong_to_same_tenant_when_set_on_department(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [$ownerB, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);
        $foreignManager = $this->makeEmployee($tenantA, ['employee_number' => 'EMP-A1']);

        // Tenant B admin cannot set a Tenant A employee as a department manager.
        $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->postJson('/api/departments', ['name' => 'Ops', 'code' => 'OPS', 'manager_employee_id' => $foreignManager->id])
            ->assertStatus(422);
    }
}
