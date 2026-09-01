<?php

namespace Tests\Feature\Employees;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Employees\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_create_employee_generates_unique_number(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $first = $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/employees', ['first_name' => 'A', 'last_name' => 'One'])
            ->assertCreated()->json('employee_number');
        $second = $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/employees', ['first_name' => 'B', 'last_name' => 'Two'])
            ->assertCreated()->json('employee_number');

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^EMP-\d{6}$/', $first);
    }

    public function test_employee_number_is_unique_per_tenant(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->makeEmployee($tenant, ['employee_number' => 'EMP-1000']);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/employees', ['first_name' => 'C', 'last_name' => 'Three', 'employee_number' => 'EMP-1000'])
            ->assertStatus(422);
    }

    public function test_employee_can_exist_without_a_user_account(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->makeEmployee($tenant, ['employee_number' => 'EMP-NO-USER']);

        $this->assertNull($employee->user_id);
        $this->assertNull($employee->user);
    }

    public function test_employee_and_user_remain_separate_after_linking(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        // A member user who is NOT an employee.
        $member = $this->memberWithRole($tenant, 'employee');
        $employee = $this->makeEmployee($tenant, ['employee_number' => 'EMP-LINK']);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/employees/{$employee->id}/user-link", ['user_id' => $member->id])
            ->assertOk()->assertJsonPath('user_id', $member->id);

        // Distinct entities: the employee references the user, not equals it.
        $this->assertSame($member->id, $this->withinTenant($tenant, fn () => $employee->fresh()->user_id));
        $this->assertNotSame($employee->id, $member->id);
    }

    public function test_cannot_link_a_user_from_another_tenant(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);
        $foreignUser = $this->memberWithRole($tenantB, 'employee'); // member of B only
        $employee = $this->makeEmployee($tenantA, ['employee_number' => 'EMP-A']);

        $this->actingAs($ownerA)->withHeaders($this->tenantHeaders($tenantA))
            ->postJson("/api/employees/{$employee->id}/user-link", ['user_id' => $foreignUser->id])
            ->assertStatus(422);
    }

    public function test_cannot_link_a_user_to_two_employees(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $member = $this->memberWithRole($tenant, 'employee');
        $e1 = $this->makeEmployee($tenant, ['employee_number' => 'EMP-1']);
        $e2 = $this->makeEmployee($tenant, ['employee_number' => 'EMP-2']);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/employees/{$e1->id}/user-link", ['user_id' => $member->id])->assertOk();
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/employees/{$e2->id}/user-link", ['user_id' => $member->id])->assertStatus(422);
    }

    public function test_status_change_and_archive(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->makeEmployee($tenant, ['employee_number' => 'EMP-S']);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/employees/{$employee->id}/status", ['employment_status' => 'suspended'])
            ->assertOk()->assertJsonPath('employment_status', 'suspended');

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/employees/{$employee->id}/archive")->assertOk();

        // Archived => soft-deleted, hidden from default queries.
        $this->assertSame(0, $this->withinTenant($tenant, fn () => Employee::query()->whereKey($employee->id)->count()));
        $this->assertSame(1, $this->withinTenant($tenant, fn () => Employee::withTrashed()->whereKey($employee->id)->count()));
    }

    public function test_employee_user_link_is_audited(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $member = $this->memberWithRole($tenant, 'employee');
        $employee = $this->makeEmployee($tenant, ['employee_number' => 'EMP-AUD']);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/employees/{$employee->id}/user-link", ['user_id' => $member->id])->assertOk();

        $actions = $this->withinTenant($tenant, fn () => AuditLog::query()->pluck('action')->all());
        $this->assertContains('employee.user_linked', $actions);
        // And an HR history event was recorded (distinct from audit).
        $this->assertContains('user_linked', $this->withinTenant($tenant, fn () => $employee->historyEvents()->pluck('event_type')->all()));
    }
}
