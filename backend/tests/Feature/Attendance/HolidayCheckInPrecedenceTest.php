<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceDayMaterializer;
use App\Modules\Attendance\Services\AttendanceExceptionService;
use App\Modules\Attendance\Services\AttendanceSettingsService;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\HolidayCalendarService;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Explicit holiday × check-in precedence: a holiday means the employee is not
 * normally expected to work, so self check-in follows the off_day_work_policy,
 * and an approved exception may authorize holiday work. Real holiday attendance
 * is never overwritten by holiday materialization. 2026-03-02 is a Monday.
 */
class HolidayCheckInPrecedenceTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Hol', 'last_name' => 'Iday', 'employment_status' => 'active',
            ]);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '08:00', 'end_time' => '16:00'];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC', 'grace_minutes' => 15], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $employee->fresh();
        });
    }

    private function companyHoliday(Tenant $tenant, mixed $owner): void
    {
        $this->withinTenant($tenant, function () use ($owner) {
            $cal = app(HolidayCalendarService::class)->createCalendar(['name' => 'Nat', 'code' => 'NAT'], $owner);
            app(HolidayCalendarService::class)->addHoliday($cal, ['name' => 'Founding Day', 'date' => '2026-03-02'], $owner);
            app(HolidayCalendarService::class)->assign($cal, ['scope_type' => 'company', 'effective_from' => '2026-01-01'], $owner);
        });
    }

    public function test_holiday_reject_policy_blocks_checkin(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);
        $this->companyHoliday($tenant, $owner);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            // Default off_day_work_policy = reject.
            $this->expectException(ValidationException::class);
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 09:00:00', 'UTC'));
        });
    }

    public function test_holiday_require_approval_without_exception_blocks(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);
        $this->companyHoliday($tenant, $owner);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceSettingsService::class)->update(['off_day_work_policy' => 'require_approval'], $owner);
            $this->expectException(ValidationException::class);
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 09:00:00', 'UTC'));
        });
    }

    public function test_holiday_with_approved_exception_is_allowed(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);
        $this->companyHoliday($tenant, $owner);

        $record = $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceExceptionService::class)->create($employee, [
                'type' => 'off_day_work', 'effective_from' => '2026-03-02', 'effective_until' => '2026-03-02',
                'reason' => 'Holiday coverage',
            ], $owner);

            return app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 09:00:00', 'UTC'));
        });

        $this->assertNotNull($record->check_in_at);
        $this->assertContains($record->status, [AttendanceStatus::Present, AttendanceStatus::Late]);
    }

    public function test_holiday_allow_policy_permits_checkin(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);
        $this->companyHoliday($tenant, $owner);

        $record = $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceSettingsService::class)->update(['off_day_work_policy' => 'allow'], $owner);

            return app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
        });

        $this->assertNotNull($record->check_in_at);
    }

    public function test_real_holiday_attendance_not_overwritten_by_materializer(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);
        $this->companyHoliday($tenant, $owner);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceSettingsService::class)->update(['off_day_work_policy' => 'allow'], $owner);
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));

            app(AttendanceDayMaterializer::class)->materialize(
                CarbonImmutable::parse('2026-03-02', 'UTC'),
                CarbonImmutable::parse('2026-03-02 20:00:00', 'UTC'),
            );

            $record = AttendanceRecord::where('employee_id', $employee->getKey())->firstOrFail();
            $this->assertFalse($record->is_materialized);
            $this->assertNotSame(AttendanceStatus::Holiday, $record->status); // real punch preserved
            $this->assertNotNull($record->check_in_at);
        });
    }
}
