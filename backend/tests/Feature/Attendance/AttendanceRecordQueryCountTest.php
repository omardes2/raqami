<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 10 hardening — regression guard for the attendance-records N+1.
 *
 * AttendanceRecordResource::canViewLocation() runs a per-row scope check
 * (EmployeeScopeResolver → AccessService::scopeGrantsFor), which previously
 * issued one role_assignments query PER record. AccessService is now a
 * request-lifetime singleton that memoizes a user's role assignments, so the
 * grant lookup collapses to a small constant regardless of how many records
 * the listing returns. This test fails loudly if that memoization regresses.
 */
class AttendanceRecordQueryCountTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function companySchedule(): void
    {
        $days = [];
        for ($w = 0; $w <= 6; $w++) {
            $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '08:00', 'end_time' => '16:00'];
        }
        $s = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
        app(WorkScheduleService::class)->assign($s, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);
    }

    private function checkIn(Employee $employee): AttendanceRecord
    {
        return app(CheckInService::class)->checkIn(
            $employee,
            new PunchInput(latitude: 24.7136, longitude: 46.6753, accuracyMeters: 5),
            null,
            CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'),
        );
    }

    public function test_records_listing_does_not_scale_role_assignment_queries_with_row_count(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        // Enough rows that a per-row grant lookup would be unmistakable.
        $recordCount = 8;
        $this->withinTenant($tenant, function () use ($recordCount) {
            $this->companySchedule();
            for ($i = 0; $i < $recordCount; $i++) {
                $e = app(EmployeeService::class)->create([
                    'first_name' => "E{$i}",
                    'last_name' => 'T',
                    'employment_status' => 'active',
                ]);
                $this->checkIn($e->fresh());
            }
        });

        // Owner holds attendance.view + attendance.view_location company-wide, so
        // every row exercises the per-row location scope check.
        $owner = $tenant->owner_user_id;
        $viewer = User::query()->findOrFail($owner);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/records?per_page=100')
            ->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Confirm the fixture actually produced multiple rows.
        $this->assertCount($recordCount, $response->json('data'));

        $roleAssignmentQueries = collect($queries)
            ->filter(fn (array $q) => str_contains($q['query'], 'role_assignments'))
            ->count();

        // With per-request memoization the grant lookup is a small constant
        // (route gate + at most one warm-up), never O(row count). Pre-fix this
        // would have been >= $recordCount.
        $this->assertLessThanOrEqual(
            2,
            $roleAssignmentQueries,
            "Expected role_assignments lookups to be memoized to a small constant, got {$roleAssignmentQueries} for {$recordCount} rows (N+1 regression)."
        );
    }
}
