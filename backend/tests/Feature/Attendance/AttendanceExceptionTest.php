<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\AttendanceLocation;
use App\Modules\Attendance\Services\AttendanceExceptionService;
use App\Modules\Attendance\Services\AttendanceSettingsService;
use App\Modules\Attendance\Services\CheckInService;
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
 * Authorized attendance exceptions: off-day work and remote/field mode. An
 * employee cannot self-declare these; the exception (created by an actor) is the
 * authorization that check-in honors. Off-day work is never silently accepted.
 */
class AttendanceExceptionTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** Mon–Fri 08:00–16:00; weekend (Sat/Sun) is off. 2026-03-07 is a Saturday. */
    private function employeeWithSchedule(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Omar', 'last_name' => 'K', 'employment_status' => 'active',
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

    public function test_off_day_work_is_rejected_without_exception(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            // Default off_day_work_policy is 'reject'. Saturday punch is refused.
            $this->expectException(ValidationException::class);
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-07 09:00:00', 'UTC'));
        });
    }

    public function test_off_day_work_allowed_with_active_exception(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceExceptionService::class)->create($employee, [
                'type' => 'off_day_work',
                'effective_from' => '2026-03-07',
                'effective_until' => '2026-03-07',
                'reason' => 'Weekend coverage',
            ], $owner);

            $record = app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-07 09:00:00', 'UTC'));

            $this->assertNotNull($record->check_in_at);
            $this->assertSame(1, $record->sessions()->count());
        });
    }

    public function test_remote_exception_skips_geofence(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceSettingsService::class)->update(['geofence_required' => true], $owner);
            AttendanceLocation::create([
                'name' => 'HQ', 'latitude' => 24.7136, 'longitude' => 46.6753, 'radius_meters' => 100,
            ]);

            app(AttendanceExceptionService::class)->create($employee, [
                'type' => 'remote',
                'effective_from' => '2026-03-02',
                'effective_until' => '2026-03-06',
                'reason' => 'Working from home',
            ], $owner);

            // Monday, far from HQ, but remote mode → geofence not enforced.
            $record = app(CheckInService::class)->checkIn(
                $employee,
                new PunchInput(latitude: 24.9000, longitude: 46.9000, accuracyMeters: 5),
                $owner,
                CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'),
            );

            $this->assertNotNull($record->check_in_at);
            $this->assertSame('remote', $record->attendance_mode);
        });
    }

    public function test_alternate_location_target_must_exist_in_tenant(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employeeWithSchedule($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            $this->expectException(ValidationException::class);
            app(AttendanceExceptionService::class)->create($employee, [
                'type' => 'alternate_location',
                'effective_from' => '2026-03-02',
                'alternate_location_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
                'reason' => 'Temporary site',
            ], $owner);
        });
    }
}
