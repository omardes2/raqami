<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\AttendanceAnomaly;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\OvertimeApproval;
use App\Modules\Attendance\Services\AttendanceExceptionService;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\HolidayCalendarService;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Proves PostgreSQL RLS — not just the app scope — isolates EVERY new Sprint 4
 * tenant table across tenants, using RAW SQL that bypasses Eloquent entirely,
 * plus that the platform read-only context can never write them.
 */
class AttendanceSprint4IsolationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private const TABLES = [
        'holiday_calendars', 'holidays', 'holiday_calendar_assignments',
        'work_schedule_segments', 'attendance_sessions', 'attendance_exceptions',
        'overtime_approvals', 'attendance_anomalies',
    ];

    /** Populate one row in every Sprint 4 table inside a tenant. */
    private function seedTenant(Tenant $tenant): void
    {
        $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Iso', 'last_name' => 'Late', 'employment_status' => 'active',
            ]);

            // Schedule where every day carries a segment (work_schedule_segments).
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = [
                    'weekday' => $w, 'is_working_day' => true,
                    'segments' => [['start_time' => '08:00', 'end_time' => '16:00']],
                ];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            // Session (attendance_sessions) via a real check-in on a Monday.
            app(CheckInService::class)->checkIn($employee->fresh(), new PunchInput, null, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));

            // Holiday calendar + holiday + assignment.
            $calendar = app(HolidayCalendarService::class)->createCalendar(['name' => 'Nat', 'code' => 'NAT']);
            app(HolidayCalendarService::class)->addHoliday($calendar, ['name' => 'H', 'date' => '2026-03-10']);
            app(HolidayCalendarService::class)->assign($calendar, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            // Exception.
            app(AttendanceExceptionService::class)->create($employee->fresh(), [
                'type' => 'remote', 'effective_from' => '2026-03-02', 'reason' => 'x',
            ], $this->makeUser());

            // Overtime + anomaly (created directly; BelongsToTenant stamps tenant_id).
            $record = AttendanceRecord::query()->first();
            OvertimeApproval::query()->create([
                'attendance_record_id' => $record->getKey(), 'employee_id' => $employee->getKey(),
                'work_date' => '2026-03-02', 'calculated_minutes' => 30, 'status' => 'pending',
            ]);
            AttendanceAnomaly::query()->create([
                'employee_id' => $employee->getKey(), 'type' => 'long_session', 'severity' => 'info',
                'detected_at' => now(), 'status' => 'open', 'dedupe_key' => 'iso:'.Str::ulid(),
            ]);
        });
    }

    public function test_rls_isolates_every_sprint4_table(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);

        $this->seedTenant($tenantA);

        // Within A: every table has rows (sanity).
        $this->withinTenant($tenantA, function () {
            foreach (self::TABLES as $table) {
                $this->assertGreaterThan(0, DB::table($table)->count(), "A should see rows in {$table}");
            }
        });

        // Within B: raw SQL (RLS) returns none of A's rows.
        $this->withinTenant($tenantB, function () {
            foreach (self::TABLES as $table) {
                $this->assertSame(0, DB::table($table)->count(), "B must not see {$table} rows from A");
            }
        });
    }

    public function test_platform_readonly_cannot_write_sprint4_tables(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $this->seedTenant($tenant);
        $context = app(TenantContext::class);

        $affected = $context->runAsPlatform(fn () => DB::table('attendance_anomalies')->update(['status' => 'dismissed']));
        $this->assertSame(0, $affected);

        $affected = $context->runAsPlatform(fn () => DB::table('overtime_approvals')->update(['status' => 'approved']));
        $this->assertSame(0, $affected);
    }
}
