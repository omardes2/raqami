<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\AttendanceLocation;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Organization\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Input validation & tenant-integrity hardening: mandatory manual reason,
 * tenant-safe location branch, and well-formed report date ranges.
 */
class AttendanceValidationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function scheduledEmployee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, function () {
            $e = app(EmployeeService::class)->create(['first_name' => 'M', 'last_name' => 'N', 'employment_status' => 'active']);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '08:00', 'end_time' => '16:00'];
            }
            $s = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($s, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $e;
        });
    }

    public function test_manual_attendance_requires_a_reason(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->scheduledEmployee($tenant);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/attendance/records/manual', [
                'employee_id' => $employee->id,
                'check_in_at' => '2026-03-02 08:00:00',
                'check_out_at' => '2026-03-02 16:00:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_manual_attendance_with_reason_succeeds_and_is_audited(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->scheduledEmployee($tenant);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/attendance/records/manual', [
                'employee_id' => $employee->id,
                'check_in_at' => '2026-03-02 08:00:00',
                'check_out_at' => '2026-03-02 16:00:00',
                'reason' => 'Forgot to punch; verified by manager.',
            ])
            ->assertCreated()
            ->assertJsonPath('is_manual', true);

        $this->withinTenant($tenant, function () {
            $audit = AuditLog::query()->where('action', 'attendance.manual_recorded')->first();
            $this->assertNotNull($audit);
            $this->assertSame('Forgot to punch; verified by manager.', $audit->metadata['reason']);
        });
    }

    public function test_location_rejects_foreign_tenant_branch(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);
        $branchB = $this->makeBranch($tenantB, ['name' => 'B-HQ']);

        // Tenant A admin tries to attach tenant B's branch to a location.
        $this->actingAs($ownerA)->withHeaders($this->tenantHeaders($tenantA))
            ->postJson('/api/attendance/locations', [
                'name' => 'HQ', 'latitude' => 24.7, 'longitude' => 46.6, 'radius_meters' => 100,
                'branch_id' => $branchB->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_id');

        $this->withinTenant($tenantA, fn () => $this->assertSame(0, AttendanceLocation::count()));
    }

    public function test_location_accepts_own_tenant_branch(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $branch = $this->makeBranch($tenant, ['name' => 'HQ']);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/attendance/locations', [
                'name' => 'HQ', 'latitude' => 24.7, 'longitude' => 46.6, 'radius_meters' => 100,
                'branch_id' => $branch->id,
            ])
            ->assertCreated()
            ->assertJsonPath('branch_id', $branch->id);
    }

    public function test_report_rejects_malformed_and_inverted_dates(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/reports/summary?from=not-a-date')
            ->assertStatus(422)->assertJsonValidationErrors('from');

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/reports/summary?from=2026-03-10&to=2026-03-01')
            ->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_report_accepts_valid_range(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/reports/summary?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->assertJsonPath('summary.records', 0);
    }
}
