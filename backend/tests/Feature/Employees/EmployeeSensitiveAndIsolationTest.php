<?php

namespace Tests\Feature\Employees;

use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class EmployeeSensitiveAndIsolationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    // --- Sensitive data ---

    public function test_sensitive_fields_hidden_from_unauthorized_role(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->makeEmployee($tenant, [
            'employee_number' => 'EMP-SEN',
            'personal_email' => 'private@example.com',
            'mobile_phone' => '+123456789',
        ]);
        // department-manager does NOT have employees.view_sensitive.
        $manager = $this->memberWithRole($tenant, 'department-manager');

        $response = $this->actingAs($manager)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$employee->id}")->assertOk();

        $response->assertJsonMissingPath('sensitive');
        $this->assertStringNotContainsString('private@example.com', $response->getContent());
    }

    public function test_sensitive_fields_visible_to_hr_role(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->makeEmployee($tenant, [
            'employee_number' => 'EMP-SEN2',
            'personal_email' => 'private@example.com',
        ]);
        $hr = $this->memberWithRole($tenant, 'hr-manager');

        $this->actingAs($hr)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('sensitive.personal_email', 'private@example.com');
    }

    public function test_list_endpoint_never_returns_sensitive_fields(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->makeEmployee($tenant, ['employee_number' => 'EMP-L', 'personal_email' => 'private@example.com']);

        $body = $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/employees')->assertOk()->getContent();

        $this->assertStringNotContainsString('private@example.com', $body);
    }

    // --- Tenant isolation (application layer) ---

    public function test_tenant_cannot_read_or_mutate_another_tenants_employee(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [$ownerB, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);
        $employeeA = $this->makeEmployee($tenantA, ['employee_number' => 'EMP-A']);

        // Tenant B owner cannot fetch / update / archive / transfer Tenant A employee.
        $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->getJson("/api/employees/{$employeeA->id}")->assertNotFound();
        $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->patchJson("/api/employees/{$employeeA->id}", ['first_name' => 'Hacked'])->assertNotFound();
        $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->postJson("/api/employees/{$employeeA->id}/archive")->assertNotFound();

        // A's employee list from B shows nothing of A.
        $listB = $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->getJson('/api/employees')->assertOk()->json('data');
        $this->assertEmpty($listB);
    }

    public function test_tenant_cannot_read_another_tenants_branches(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [$ownerB, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);
        $branchA = $this->makeBranch($tenantA, ['code' => 'A-HQ']);

        $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->getJson("/api/branches/{$branchA->id}")->assertNotFound();
        $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->getJson('/api/branches')->assertOk()->assertJsonCount(0, 'data');
    }

    // --- Tenant isolation (database / RLS layer) ---

    public function test_rls_blocks_cross_tenant_employee_rows(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);
        $employeeA = $this->makeEmployee($tenantA, ['employee_number' => 'EMP-A']);
        $branchA = $this->makeBranch($tenantA, ['code' => 'A']);

        $this->withinTenant($tenantB, function () use ($employeeA, $branchA) {
            // App scope removed, RLS still blocks employees + branches of tenant A.
            $this->assertFalse(Employee::withoutGlobalScope(TenantScope::class)->whereKey($employeeA->id)->exists());
            $this->assertFalse(DB::table('employees')->where('id', $employeeA->id)->exists());
            $this->assertFalse(DB::table('branches')->where('id', $branchA->id)->exists());
        });
    }

    public function test_private_document_download_requires_authorization(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->makeEmployee($tenant, ['employee_number' => 'EMP-DOC']);
        // Create a document row directly (metadata) within the tenant.
        $doc = $this->withinTenant($tenant, fn () => $employee->documents()->create([
            'category' => 'contract', 'title' => 'Contract', 'storage_key' => 'tenants/x/y/z.pdf',
            'original_filename' => 'c.pdf', 'mime_type' => 'application/pdf', 'size' => 10,
        ]));

        // A user with no document permission is forbidden at the route gate.
        $noPerm = $this->memberWithRole($tenant, 'employee');
        $this->actingAs($noPerm)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/employees/{$employee->id}/documents/{$doc->id}/download")
            ->assertForbidden();

        // A user from another tenant cannot see it either (scope-safe 404).
        [$ownerB, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);
        $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->getJson("/api/employees/{$employee->id}/documents/{$doc->id}/download")
            ->assertNotFound();
    }
}
