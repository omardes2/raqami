<?php

namespace Tests\Feature\Attendance;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * HTTP-level Sprint 4 endpoints: holiday calendars, exceptions, overtime,
 * anomalies. Permission-gated and tenant/scope-safe.
 */
class AttendanceAdvancedApiTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, fn () => app(EmployeeService::class)->create([
            'first_name' => 'API', 'last_name' => 'Emp', 'employment_status' => 'active',
        ]));
    }

    public function test_owner_can_manage_holiday_calendar_via_api(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $headers = $this->tenantHeaders($tenant);

        $create = $this->actingAs($owner)->withHeaders($headers)
            ->postJson('/api/attendance/holidays/calendars', ['name' => 'National', 'code' => 'NAT']);
        $create->assertCreated()->assertJsonPath('code', 'NAT');
        $calendarId = $create->json('id');

        $holiday = $this->actingAs($owner)->withHeaders($headers)
            ->postJson("/api/attendance/holidays/calendars/{$calendarId}/holidays", [
                'name' => 'Founding Day', 'date' => '2026-03-02',
            ]);
        $holiday->assertCreated()->assertJsonPath('date', '2026-03-02');

        $assign = $this->actingAs($owner)->withHeaders($headers)
            ->postJson("/api/attendance/holidays/calendars/{$calendarId}/assignments", [
                'scope_type' => 'company', 'effective_from' => '2026-01-01',
            ]);
        $assign->assertCreated();

        $this->actingAs($owner)->withHeaders($headers)
            ->getJson('/api/attendance/holidays/calendars')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_manager_can_create_exception_via_api(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $manager = $this->memberWithRole($tenant, 'hr-manager');
        $employee = $this->employee($tenant);

        $this->actingAs($manager)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/attendance/exceptions', [
                'employee_id' => $employee->getKey(),
                'type' => 'remote',
                'effective_from' => '2026-03-02',
                'effective_until' => '2026-03-06',
                'reason' => 'Remote week',
            ])
            ->assertCreated()
            ->assertJsonPath('type', 'remote')
            ->assertJsonPath('attendance_mode', 'remote');
    }

    public function test_plain_employee_cannot_list_exceptions(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $user = $this->memberWithRole($tenant, 'employee');

        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/exceptions')
            ->assertForbidden();
    }

    public function test_overtime_and_anomaly_lists_are_permission_gated(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $headers = $this->tenantHeaders($tenant);

        $this->actingAs($owner)->withHeaders($headers)->getJson('/api/attendance/overtime')->assertOk();
        $this->actingAs($owner)->withHeaders($headers)->getJson('/api/attendance/anomalies')->assertOk();

        $employeeUser = $this->memberWithRole($tenant, 'employee');
        $this->actingAs($employeeUser)->withHeaders($headers)->getJson('/api/attendance/overtime')->assertForbidden();
        $this->actingAs($employeeUser)->withHeaders($headers)->getJson('/api/attendance/anomalies')->assertForbidden();
    }

    public function test_cross_tenant_calendar_is_not_visible(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        [$ownerB, $tenantB] = $this->createCompanyWithOwner();

        $create = $this->actingAs($ownerA)->withHeaders($this->tenantHeaders($tenantA))
            ->postJson('/api/attendance/holidays/calendars', ['name' => 'A-Cal', 'code' => 'ACAL']);
        $create->assertCreated();

        // Tenant B sees none of tenant A's calendars.
        $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->getJson('/api/attendance/holidays/calendars')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
