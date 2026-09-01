<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\AttendanceLocation;
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
 * End-to-end (service layer) attendance flow against the real DB + RLS. Proves
 * the server — not the client — decides work date, lateness, worked minutes, and
 * status, and that concurrency/idempotency guards hold.
 */
class AttendanceFlowTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** Build an employee on a Mon–Fri 08:00–16:00 schedule (UTC), assigned company-wide. */
    private function employeeWithSchedule(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Sara', 'last_name' => 'Ali', 'employment_status' => 'active',
            ]);

            $days = [];
            for ($weekday = 0; $weekday <= 6; $weekday++) {
                $working = $weekday >= 1 && $weekday <= 5; // Mon..Fri
                $days[] = [
                    'weekday' => $weekday,
                    'is_working_day' => $working,
                    'start_time' => $working ? '08:00' : null,
                    'end_time' => $working ? '16:00' : null,
                ];
            }

            $schedule = app(WorkScheduleService::class)->create(
                ['name' => 'Standard', 'code' => 'STD', 'timezone' => 'UTC', 'grace_minutes' => 15],
                $days,
            );

            app(WorkScheduleService::class)->assign($schedule, [
                'scope_type' => 'company',
                'effective_from' => '2026-01-01',
            ]);

            return $employee;
        });
    }

    public function test_check_in_then_check_out_computes_worked_minutes(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        // 2026-03-02 is a Monday.
        $this->withinTenant($tenant, function () use ($employee, $owner) {
            $record = app(CheckInService::class)->checkIn(
                $employee,
                new PunchInput,
                $owner,
                CarbonImmutable::parse('2026-03-02 08:05:00', 'UTC'), // within grace
            );

            $this->assertSame(AttendanceStatus::Present, $record->status);
            $this->assertSame(0, $record->late_minutes);
            $this->assertTrue($record->isOpen());

            $closed = app(CheckOutService::class)->checkOut(
                $employee,
                new PunchInput,
                $owner,
                CarbonImmutable::parse('2026-03-02 16:00:00', 'UTC'),
            );

            $this->assertNotNull($closed->check_out_at);
            $this->assertSame(475, $closed->worked_minutes); // 08:05 -> 16:00

            // One daily record, two raw events (check-in + check-out).
            $this->assertSame(1, AttendanceRecord::count());
            $this->assertSame(2, AttendanceEvent::count());
        });
    }

    public function test_late_check_in_is_flagged_by_server(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            $record = app(CheckInService::class)->checkIn(
                $employee,
                new PunchInput,
                $owner,
                CarbonImmutable::parse('2026-03-02 09:00:00', 'UTC'), // 60m late, grace 15 => 45
            );

            $this->assertSame(AttendanceStatus::Late, $record->status);
            $this->assertSame(45, $record->late_minutes);
        });
    }

    public function test_double_check_in_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));

            $this->expectException(ValidationException::class);
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:30:00', 'UTC'));
        });
    }

    public function test_idempotent_check_in_returns_same_record(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            $input = new PunchInput(clientRequestId: 'req-123');

            $first = app(CheckInService::class)->checkIn($employee, $input, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
            $second = app(CheckInService::class)->checkIn($employee, $input, $owner, CarbonImmutable::parse('2026-03-02 08:00:05', 'UTC'));

            $this->assertSame($first->id, $second->id);
            $this->assertSame(1, AttendanceRecord::count());
        });
    }

    public function test_ineligible_employee_cannot_check_in(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Sus', 'last_name' => 'Pended', 'employment_status' => 'suspended',
            ]);

            $this->expectException(ValidationException::class);
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
        });
    }

    public function test_geofence_required_blocks_outside_punch(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceSettingsService::class)->update(['geofence_required' => true], $owner);

            AttendanceLocation::create([
                'name' => 'HQ', 'latitude' => 24.7136, 'longitude' => 46.6753, 'radius_meters' => 100,
            ]);

            // ~330m away → outside.
            $this->expectException(ValidationException::class);
            app(CheckInService::class)->checkIn(
                $employee,
                new PunchInput(latitude: 24.7166, longitude: 46.6753, accuracyMeters: 5),
                $owner,
                CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'),
            );
        });
    }
}
