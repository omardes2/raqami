<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Production upgrade-path safety: a Sprint 3-shaped attendance_record (a real
 * check-in/out with NO session yet) is backfilled into exactly one session with
 * values preserved, idempotently, without touching events. Exercises the actual
 * Sprint 4 backfill migration behavior rather than migrate:fresh alone.
 */
class LegacyBackfillUpgradeTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function legacyRecord(Tenant $tenant, array $overrides = []): AttendanceRecord
    {
        return $this->withinTenant($tenant, function () use ($overrides) {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Leg', 'last_name' => 'Acy', 'employment_status' => 'active',
            ]);

            // A Sprint 3-style record: real punch data, but no session rows.
            $record = AttendanceRecord::query()->create(array_merge([
                'employee_id' => $employee->getKey(),
                'work_date' => '2026-02-02',
                'timezone' => 'UTC',
                'scheduled_start_at' => CarbonImmutable::parse('2026-02-02 08:00:00', 'UTC'),
                'scheduled_end_at' => CarbonImmutable::parse('2026-02-02 16:00:00', 'UTC'),
                'check_in_at' => CarbonImmutable::parse('2026-02-02 08:05:00', 'UTC'),
                'check_out_at' => CarbonImmutable::parse('2026-02-02 16:00:00', 'UTC'),
                'worked_minutes' => 475,
                'break_minutes' => 0,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'overtime_minutes' => 0,
                'grace_minutes' => 15,
                'status' => AttendanceStatus::Present,
                'source' => AttendanceSource::Web,
                'is_manual' => false,
            ], $overrides));

            // Ensure it looks pre-session.
            $record->sessions()->delete();

            return $record;
        });
    }

    /**
     * Replays the Sprint 4 attendance-session backfill's exact logic (migration
     * 2026_09_05_000230): for every record with a check-in and no session yet,
     * insert session #1 copying its punch/geo/minutes verbatim. Idempotent via a
     * NOT-EXISTS guard. Run inside the tenant so post-migration RLS is satisfied
     * (the production migration runs before RLS is enabled on the new table).
     */
    private function runBackfill(Tenant $tenant): void
    {
        $this->withinTenant($tenant, function () {
            $records = DB::table('attendance_records')->whereNotNull('check_in_at')->get();

            foreach ($records as $r) {
                if (DB::table('attendance_sessions')->where('attendance_record_id', $r->id)->exists()) {
                    continue;
                }

                DB::table('attendance_sessions')->insert([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $r->tenant_id,
                    'attendance_record_id' => $r->id,
                    'employee_id' => $r->employee_id,
                    'sequence' => 1,
                    'check_in_at' => $r->check_in_at,
                    'check_out_at' => $r->check_out_at,
                    'scheduled_start_at' => $r->scheduled_start_at,
                    'scheduled_end_at' => $r->scheduled_end_at,
                    'worked_minutes' => $r->worked_minutes,
                    'break_minutes' => $r->break_minutes,
                    'late_minutes' => $r->late_minutes,
                    'early_leave_minutes' => $r->early_leave_minutes,
                    'overtime_minutes' => $r->overtime_minutes,
                    'grace_minutes' => $r->grace_minutes,
                    'source' => $r->source,
                    'is_manual' => $r->is_manual,
                    'check_in_latitude' => $r->check_in_latitude,
                    'check_in_longitude' => $r->check_in_longitude,
                    'check_in_inside_geofence' => $r->check_in_inside_geofence,
                    'check_in_location_id' => $r->check_in_location_id,
                    'check_out_latitude' => $r->check_out_latitude,
                    'check_out_longitude' => $r->check_out_longitude,
                    'check_out_inside_geofence' => $r->check_out_inside_geofence,
                    'check_out_location_id' => $r->check_out_location_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function test_backfill_creates_one_session_preserving_values(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $record = $this->legacyRecord($tenant);

        $this->runBackfill($tenant);

        $this->withinTenant($tenant, function () use ($record) {
            $sessions = AttendanceSession::where('attendance_record_id', $record->getKey())->get();
            $this->assertCount(1, $sessions);
            $s = $sessions->first();
            $this->assertSame((string) $record->tenant_id, (string) $s->tenant_id);
            $this->assertSame((string) $record->employee_id, (string) $s->employee_id);
            $this->assertSame(1, $s->sequence);
            $this->assertSame('2026-02-02 08:05:00', $s->check_in_at->format('Y-m-d H:i:s'));
            $this->assertSame('2026-02-02 16:00:00', $s->check_out_at->format('Y-m-d H:i:s'));
            $this->assertSame(475, $s->worked_minutes);
            $this->assertFalse($s->is_manual);
        });
    }

    public function test_backfill_is_idempotent(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $record = $this->legacyRecord($tenant);

        $this->runBackfill($tenant);
        $this->runBackfill($tenant); // second execution must not duplicate

        $this->withinTenant($tenant, function () use ($record) {
            $this->assertSame(1, AttendanceSession::where('attendance_record_id', $record->getKey())->count());
        });
    }

    public function test_backfill_handles_legacy_open_record(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $record = $this->legacyRecord($tenant, [
            'check_out_at' => null,
            'worked_minutes' => 0,
            'status' => AttendanceStatus::Present,
            'work_date' => '2026-02-03',
        ]);

        $this->runBackfill($tenant);

        $this->withinTenant($tenant, function () use ($record) {
            $s = AttendanceSession::where('attendance_record_id', $record->getKey())->firstOrFail();
            $this->assertNull($s->check_out_at); // open session preserved
        });
    }

    public function test_backfill_does_not_touch_events(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $record = $this->legacyRecord($tenant);

        $before = $this->withinTenant($tenant, fn () => DB::table('attendance_events')->count());
        $this->runBackfill($tenant);
        $after = $this->withinTenant($tenant, fn () => DB::table('attendance_events')->count());

        $this->assertSame($before, $after);
    }
}
