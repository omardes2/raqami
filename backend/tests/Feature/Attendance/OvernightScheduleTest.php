<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\CheckOutService;
use App\Modules\Attendance\Services\ScheduleResolver;
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
 * Overnight schedules (e.g. 22:00 -> 06:00). A post-midnight punch that still
 * falls inside the previous local day's window must resolve to that PREVIOUS
 * work_date — one overnight shift is one record, lateness measures from 22:00.
 */
class OvernightScheduleTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** Every day is an overnight 22:00 -> 06:00 shift, assigned to the employee. */
    private function nightEmployee(Tenant $tenant, string $timezone = 'UTC'): Employee
    {
        return $this->withinTenant($tenant, function () use ($timezone) {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Noor', 'last_name' => 'Layl', 'employment_status' => 'active',
            ]);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '22:00', 'end_time' => '06:00'];
            }
            $schedule = app(WorkScheduleService::class)->create(
                ['name' => 'Night', 'code' => 'NIGHT', 'timezone' => $timezone, 'grace_minutes' => 15], $days,
            );
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $employee->fresh();
        });
    }

    public function test_check_in_at_2200_resolves_to_that_day(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->nightEmployee($tenant);

        $this->withinTenant($tenant, function () use ($employee) {
            // 2026-03-02 is a Monday.
            $r = app(ScheduleResolver::class)->resolveWorkDay(
                $employee, CarbonImmutable::parse('2026-03-02 22:00:00', 'UTC'), 'UTC',
            );
            $this->assertSame('2026-03-02', $r->workDate->toDateString());
            $this->assertSame('2026-03-02 22:00:00', $r->scheduledStartAt->format('Y-m-d H:i:s'));
            $this->assertSame('2026-03-03 06:00:00', $r->scheduledEndAt->format('Y-m-d H:i:s'));
        });
    }

    public function test_post_midnight_punch_reaches_back_to_previous_work_date(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->nightEmployee($tenant);

        $this->withinTenant($tenant, function () use ($employee) {
            // Tuesday 01:00 belongs to Monday's 22:00 -> Tuesday 06:00 shift.
            $r = app(ScheduleResolver::class)->resolveWorkDay(
                $employee, CarbonImmutable::parse('2026-03-03 01:00:00', 'UTC'), 'UTC',
            );
            $this->assertSame('2026-03-02', $r->workDate->toDateString());
            $this->assertSame('2026-03-02 22:00:00', $r->scheduledStartAt->format('Y-m-d H:i:s'));
            $this->assertSame('2026-03-03 06:00:00', $r->scheduledEndAt->format('Y-m-d H:i:s'));
        });
    }

    public function test_late_check_in_at_0100_is_late_from_previous_day_start(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->nightEmployee($tenant);

        $record = $this->withinTenant($tenant, fn () => app(CheckInService::class)->checkIn(
            $employee, new PunchInput, null, CarbonImmutable::parse('2026-03-03 01:00:00', 'UTC'),
        ));

        // 01:00 is 3h after the 22:00 start; grace 15 => 180 - 15 = 165 late.
        $this->assertSame('2026-03-02', $record->work_date->toDateString());
        $this->assertSame(165, $record->late_minutes);
        $this->assertSame(AttendanceStatus::Late, $record->status);
    }

    public function test_checkout_next_morning_closes_same_record(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->nightEmployee($tenant);

        $this->withinTenant($tenant, function () use ($employee) {
            $in = app(CheckInService::class)->checkIn(
                $employee, new PunchInput, null, CarbonImmutable::parse('2026-03-02 22:00:00', 'UTC'),
            );
            $out = app(CheckOutService::class)->checkOut(
                $employee, new PunchInput, null, CarbonImmutable::parse('2026-03-03 06:00:00', 'UTC'),
            );

            $this->assertSame($in->id, $out->id);
            $this->assertSame('2026-03-02', $out->work_date->toDateString());
            $this->assertSame(480, $out->worked_minutes); // 22:00 -> 06:00
            $this->assertSame(0, $out->early_leave_minutes);
            $this->assertSame(1, AttendanceRecord::count());
        });
    }

    public function test_report_keeps_overnight_record_under_previous_work_date(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->nightEmployee($tenant);

        $this->withinTenant($tenant, function () use ($employee) {
            app(CheckInService::class)->checkIn(
                $employee, new PunchInput, null, CarbonImmutable::parse('2026-03-03 01:00:00', 'UTC'),
            );

            // The record is filed under Monday, not Tuesday.
            $this->assertTrue(AttendanceRecord::whereDate('work_date', '2026-03-02')->exists());
            $this->assertFalse(AttendanceRecord::whereDate('work_date', '2026-03-03')->exists());
        });
    }

    public function test_next_evening_shift_is_a_separate_work_date(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->nightEmployee($tenant);

        $this->withinTenant($tenant, function () use ($employee) {
            // Monday night shift, checked out.
            app(CheckInService::class)->checkIn($employee, new PunchInput, null, CarbonImmutable::parse('2026-03-02 22:00:00', 'UTC'));
            app(CheckOutService::class)->checkOut($employee, new PunchInput, null, CarbonImmutable::parse('2026-03-03 06:00:00', 'UTC'));

            // Tuesday night shift begins — a new, separate record.
            $second = app(CheckInService::class)->checkIn($employee, new PunchInput, null, CarbonImmutable::parse('2026-03-03 22:00:00', 'UTC'));

            $this->assertSame('2026-03-03', $second->work_date->toDateString());
            $this->assertSame(2, AttendanceRecord::count());
        });
    }

    public function test_overnight_reach_back_in_non_utc_timezone(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        // Asia/Hebron is UTC+2 (standard) / UTC+3 (DST). Early March = +02:00.
        $employee = $this->nightEmployee($tenant, 'Asia/Hebron');

        $this->withinTenant($tenant, function () use ($employee) {
            // Local Tuesday 01:00 Hebron (+02:00) == 2026-03-02 23:00 UTC.
            $instant = CarbonImmutable::parse('2026-03-03 01:00:00', 'Asia/Hebron')->utc();

            $r = app(ScheduleResolver::class)->resolveWorkDay($employee, $instant, 'Asia/Hebron');

            $this->assertSame('2026-03-02', $r->workDate->toDateString());
            // Local Monday 22:00 Hebron == 2026-03-02 20:00 UTC.
            $this->assertSame('2026-03-02 20:00:00', $r->scheduledStartAt->format('Y-m-d H:i:s'));
            // Local Tuesday 06:00 Hebron == 2026-03-03 04:00 UTC.
            $this->assertSame('2026-03-03 04:00:00', $r->scheduledEndAt->format('Y-m-d H:i:s'));
        });
    }

    public function test_ordinary_daytime_schedule_is_unaffected(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $employee = $this->withinTenant($tenant, function () {
            $e = app(EmployeeService::class)->create([
                'first_name' => 'Day', 'last_name' => 'Shift', 'employment_status' => 'active',
            ]);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '08:00', 'end_time' => '16:00'];
            }
            $s = app(WorkScheduleService::class)->create(['name' => 'Day', 'code' => 'DAY', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($s, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $e->fresh();
        });

        $this->withinTenant($tenant, function () use ($employee) {
            $r = app(ScheduleResolver::class)->resolveWorkDay(
                $employee, CarbonImmutable::parse('2026-03-03 08:05:00', 'UTC'), 'UTC',
            );
            // No reach-back for daytime schedules.
            $this->assertSame('2026-03-03', $r->workDate->toDateString());
            $this->assertSame('2026-03-03 08:00:00', $r->scheduledStartAt->format('Y-m-d H:i:s'));
        });
    }
}
