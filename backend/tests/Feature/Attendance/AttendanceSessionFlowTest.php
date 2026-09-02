<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceSettingsService;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\CheckOutService;
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
 * Sprint 4 session model: multiple sessions per work_date (split shifts), a single
 * open session per employee, overlap prevention, and daily aggregation from the
 * record's sessions. The daily attendance_record is a derived aggregate.
 */
class AttendanceSessionFlowTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** Split-shift schedule: 08:00–12:00 and 16:00–20:00 every day, company-wide. */
    private function splitShiftEmployee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Nour', 'last_name' => 'S', 'employment_status' => 'active',
            ]);

            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = [
                    'weekday' => $w,
                    'is_working_day' => true,
                    'segments' => [
                        ['start_time' => '08:00', 'end_time' => '12:00'],
                        ['start_time' => '16:00', 'end_time' => '20:00'],
                    ],
                ];
            }
            $schedule = app(WorkScheduleService::class)->create(
                ['name' => 'Split', 'code' => 'SPLIT', 'timezone' => 'UTC', 'grace_minutes' => 10],
                $days,
            );
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $employee->fresh();
        });
    }

    public function test_split_shift_two_sessions_aggregate_into_one_record(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->splitShiftEmployee($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceSettingsService::class)->update([
                'allow_multiple_sessions' => true, 'allow_unscheduled_work' => true,
            ], $owner);

            // Morning session 08:00 -> 12:00 (240 min).
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
            $morning = app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 12:00:00', 'UTC'));
            $this->assertFalse($morning->isOpen());

            // Afternoon session 16:00 -> 20:00 (240 min).
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 16:00:00', 'UTC'));
            $record = app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 20:00:00', 'UTC'));

            $this->assertSame(1, AttendanceRecord::count());
            $this->assertSame(2, $record->sessions()->count());
            $this->assertSame(480, $record->worked_minutes); // 240 + 240
            $this->assertSame(0, $record->late_minutes);
            $this->assertSame(AttendanceStatus::Present, $record->status);
            $this->assertNotNull($record->check_in_at);
            $this->assertNotNull($record->check_out_at);
        });
    }

    public function test_single_session_disallows_second_session_when_not_enabled(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->splitShiftEmployee($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            // allow_multiple_sessions defaults off.
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
            app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 12:00:00', 'UTC'));

            $this->expectException(ValidationException::class);
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 16:00:00', 'UTC'));
        });
    }

    public function test_only_one_open_session_allowed(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->splitShiftEmployee($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceSettingsService::class)->update(['allow_multiple_sessions' => true], $owner);

            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));

            // A second check-in while one is still open is rejected.
            $this->expectException(ValidationException::class);
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 09:00:00', 'UTC'));
        });
    }

    public function test_checkout_with_no_open_session_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->splitShiftEmployee($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            $this->expectException(ValidationException::class);
            app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 12:00:00', 'UTC'));
        });
    }

    public function test_late_on_afternoon_segment_flags_day_late(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->splitShiftEmployee($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceSettingsService::class)->update([
                'allow_multiple_sessions' => true, 'allow_late_check_in' => true,
            ], $owner);

            // Morning on time.
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
            app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 12:00:00', 'UTC'));

            // Afternoon 30 min late (grace 10 => 20 late), selects 16:00 segment.
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 16:30:00', 'UTC'));
            $record = app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 20:00:00', 'UTC'));

            $this->assertSame(20, $record->late_minutes);
            $this->assertSame(AttendanceStatus::Late, $record->status);
        });
    }

    public function test_record_version_increments_on_each_aggregation(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->splitShiftEmployee($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            $open = app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
            $v1 = $open->version;

            $closed = app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 12:00:00', 'UTC'));

            $this->assertGreaterThan($v1, $closed->version);
            $this->assertFalse($closed->is_materialized);
        });
    }
}
