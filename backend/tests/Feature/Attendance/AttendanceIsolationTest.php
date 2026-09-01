<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Attendance data is sensitive (GPS + presence). These tests prove PostgreSQL
 * RLS — not just the app-level scope — isolates every attendance table across
 * tenants, using RAW SQL that bypasses Eloquent entirely.
 */
class AttendanceIsolationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function checkInEmployee(Tenant $tenant): AttendanceRecord
    {
        return $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active',
            ]);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '08:00', 'end_time' => '16:00'];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return app(CheckInService::class)->checkIn(
                $employee,
                new PunchInput(latitude: 24.7, longitude: 46.6, accuracyMeters: 5),
                null,
                CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'),
            );
        });
    }

    public function test_rls_hides_attendance_rows_across_tenants(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);

        $recordA = $this->checkInEmployee($tenantA);
        $eventIdsA = $this->withinTenant($tenantA, fn () => $recordA->events()->pluck('id')->all());

        $this->withinTenant($tenantB, function () use ($recordA, $eventIdsA) {
            // App scope removed, RLS still hides tenant A's attendance from B — via
            // Eloquent AND via raw SQL (the DB itself refuses to return the rows).
            $this->assertFalse(
                AttendanceRecord::withoutGlobalScope(TenantScope::class)->whereKey($recordA->id)->exists()
            );
            $this->assertFalse(DB::table('attendance_records')->where('id', $recordA->id)->exists());
            $this->assertFalse(DB::table('attendance_events')->whereIn('id', $eventIdsA)->exists());
            $this->assertSame(0, DB::table('attendance_records')->count());
        });
    }

    public function test_platform_readonly_cannot_write_attendance(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $record = $this->checkInEmployee($tenant);
        $context = app(TenantContext::class);

        // Platform read-only may SELECT but never UPDATE tenant attendance.
        $affected = $context->runAsPlatform(fn () => AttendanceRecord::withoutGlobalScope(TenantScope::class)
            ->whereKey($record->id)->update(['status' => 'absent']));

        $this->assertSame(0, $affected);
    }
}
