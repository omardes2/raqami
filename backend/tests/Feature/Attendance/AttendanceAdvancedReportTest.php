<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Services\AttendanceReportService;
use App\Modules\Attendance\Services\AttendanceSettingsService;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\CheckOutService;
use App\Modules\Attendance\Services\ManualAttendanceService;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Advanced attendance reporting: neutral compliance rates, status breakdown,
 * calculated-vs-approved overtime, per-employee rollup. Scope-constrained; no GPS.
 */
class AttendanceAdvancedReportTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Rep', 'last_name' => 'Ort', 'employment_status' => 'active',
            ]);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '08:00', 'end_time' => '16:00'];
            }
            $schedule = app(WorkScheduleService::class)->create(
                ['name' => 'S', 'code' => 'S', 'timezone' => 'UTC', 'grace_minutes' => 15, 'overtime_after_minutes' => 0], $days,
            );
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $employee->fresh();
        });
    }

    public function test_compliance_and_status_breakdown(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $viewer = $this->memberWithRole($tenant, 'hr-manager');
        $employee = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner, $viewer) {
            // One on-time day (Mon 08:00) and one late day (Tue 09:00).
            app(ManualAttendanceService::class)->record($employee, ['check_in_at' => '2026-03-02 08:00:00', 'check_out_at' => '2026-03-02 16:00:00', 'reason' => 'x'], $owner);
            app(ManualAttendanceService::class)->record($employee, ['check_in_at' => '2026-03-03 09:00:00', 'check_out_at' => '2026-03-03 16:00:00', 'reason' => 'x'], $owner);

            $reports = app(AttendanceReportService::class);
            $compliance = $reports->compliance($viewer, ['from' => '2026-03-01', 'to' => '2026-03-31']);

            $this->assertSame(1, $compliance['present']);
            $this->assertSame(1, $compliance['late']);
            $this->assertSame(0, $compliance['absent']);
            $this->assertSame(2, $compliance['scheduled_days']);
            $this->assertSame(1.0, $compliance['attendance_rate']);   // both attended
            $this->assertSame(0.5, $compliance['punctuality_rate']);  // one on time of two

            $breakdown = $reports->statusBreakdown($viewer, []);
            $this->assertSame(1, $breakdown['present']);
            $this->assertSame(1, $breakdown['late']);
            $this->assertArrayHasKey('weekend', $breakdown);
        });
    }

    public function test_overtime_rollup_keeps_calculated_and_approved_distinct(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $viewer = $this->memberWithRole($tenant, 'hr-manager');
        $employee = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner, $viewer) {
            app(AttendanceSettingsService::class)->update([
                'overtime_tracking_enabled' => true, 'overtime_requires_approval' => true, 'allow_late_check_in' => true,
            ], $owner);

            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
            app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 18:00:00', 'UTC'));

            $overtime = app(AttendanceReportService::class)->overtime($viewer, []);
            $this->assertSame(1, $overtime['requests']);
            $this->assertSame(1, $overtime['pending']);
            $this->assertSame(120, $overtime['calculated_minutes']);
            $this->assertSame(0, $overtime['approved_minutes']); // none approved yet
        });
    }

    public function test_by_employee_rollup_has_no_gps(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $viewer = $this->memberWithRole($tenant, 'hr-manager');
        $employee = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner, $viewer) {
            app(ManualAttendanceService::class)->record($employee, ['check_in_at' => '2026-03-02 08:00:00', 'check_out_at' => '2026-03-02 16:00:00', 'reason' => 'x'], $owner);

            $rows = app(AttendanceReportService::class)->byEmployee($viewer, []);
            $this->assertCount(1, $rows);
            $this->assertSame(480, $rows[0]['worked_minutes']);
            $this->assertArrayNotHasKey('check_in_latitude', $rows[0]);
            $this->assertArrayNotHasKey('check_in_longitude', $rows[0]);
        });
    }
}
