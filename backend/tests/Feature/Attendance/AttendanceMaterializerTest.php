<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceDayMaterializer;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\HolidayCalendarService;
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
 * Daily materialization: derives absent / weekend / holiday state for employees
 * who did not punch, honoring the absence cutoff, holiday precedence, and the
 * inviolable rule that a real punch is never overwritten. Idempotent.
 */
class AttendanceMaterializerTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** Mon–Fri 08:00–16:00 (UTC); Sat/Sun off. */
    private function employeeWithSchedule(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Lina', 'last_name' => 'H', 'employment_status' => 'active',
            ]);

            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $working = $w >= 1 && $w <= 5;
                $days[] = [
                    'weekday' => $w, 'is_working_day' => $working,
                    'start_time' => $working ? '08:00' : null,
                    'end_time' => $working ? '16:00' : null,
                ];
            }
            $schedule = app(WorkScheduleService::class)->create(
                ['name' => 'Std', 'code' => 'STD', 'timezone' => 'UTC', 'grace_minutes' => 15],
                $days,
            );
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $employee->fresh();
        });
    }

    public function test_absent_is_materialized_after_cutoff_on_working_day(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () use ($employee) {
            // Monday 2026-03-02; default cutoff 120m after 08:00 => 10:00. Now 11:00.
            $counts = app(AttendanceDayMaterializer::class)->materialize(
                CarbonImmutable::parse('2026-03-02', 'UTC'),
                CarbonImmutable::parse('2026-03-02 11:00:00', 'UTC'),
            );

            $this->assertSame(1, $counts['absent']);
            $record = AttendanceRecord::where('employee_id', $employee->getKey())->first();
            $this->assertSame(AttendanceStatus::Absent, $record->status);
            $this->assertTrue($record->is_materialized);
        });
    }

    public function test_absence_not_declared_before_cutoff(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () use ($employee) {
            // Now 09:00, before the 10:00 cutoff — no absence yet.
            $counts = app(AttendanceDayMaterializer::class)->materialize(
                CarbonImmutable::parse('2026-03-02', 'UTC'),
                CarbonImmutable::parse('2026-03-02 09:00:00', 'UTC'),
            );

            $this->assertSame(0, $counts['absent']);
            $this->assertSame(0, AttendanceRecord::where('employee_id', $employee->getKey())->count());
        });
    }

    public function test_weekend_is_materialized(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () use ($employee) {
            // 2026-03-07 is a Saturday (off day).
            $counts = app(AttendanceDayMaterializer::class)->materialize(
                CarbonImmutable::parse('2026-03-07', 'UTC'),
                CarbonImmutable::parse('2026-03-07 12:00:00', 'UTC'),
            );

            $this->assertSame(1, $counts['weekend']);
            $record = AttendanceRecord::where('employee_id', $employee->getKey())->first();
            $this->assertSame(AttendanceStatus::Weekend, $record->status);
        });
    }

    public function test_holiday_overrides_absence(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            $calendar = app(HolidayCalendarService::class)->createCalendar(['name' => 'Nat', 'code' => 'NAT'], $owner);
            app(HolidayCalendarService::class)->addHoliday($calendar, ['name' => 'Founding Day', 'date' => '2026-03-02'], $owner);
            app(HolidayCalendarService::class)->assign($calendar, ['scope_type' => 'company', 'effective_from' => '2026-01-01'], $owner);

            // Working Monday, but it is a holiday → Holiday, not Absent.
            $counts = app(AttendanceDayMaterializer::class)->materialize(
                CarbonImmutable::parse('2026-03-02', 'UTC'),
                CarbonImmutable::parse('2026-03-02 12:00:00', 'UTC'),
            );

            $this->assertSame(1, $counts['holiday']);
            $this->assertSame(0, $counts['absent']);
            $record = AttendanceRecord::where('employee_id', $employee->getKey())->first();
            $this->assertSame(AttendanceStatus::Holiday, $record->status);
            $this->assertNotNull($record->holiday_id);
        });
    }

    public function test_real_punch_is_never_overwritten(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));

            $counts = app(AttendanceDayMaterializer::class)->materialize(
                CarbonImmutable::parse('2026-03-02', 'UTC'),
                CarbonImmutable::parse('2026-03-02 11:00:00', 'UTC'),
            );

            $this->assertSame(0, $counts['absent']);
            $record = AttendanceRecord::where('employee_id', $employee->getKey())->first();
            $this->assertFalse($record->is_materialized);
            $this->assertNotSame(AttendanceStatus::Absent, $record->status);
        });
    }

    public function test_materialization_is_idempotent(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () {
            $date = CarbonImmutable::parse('2026-03-02', 'UTC');
            $now = CarbonImmutable::parse('2026-03-02 11:00:00', 'UTC');

            app(AttendanceDayMaterializer::class)->materialize($date, $now);
            app(AttendanceDayMaterializer::class)->materialize($date, $now);

            $this->assertSame(1, AttendanceRecord::count());
        });
    }
}
