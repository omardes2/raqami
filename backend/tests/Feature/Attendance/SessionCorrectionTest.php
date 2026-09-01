<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceCorrectionService;
use App\Modules\Attendance\Services\AttendanceDayMaterializer;
use App\Modules\Attendance\Services\AttendanceRecordAggregator;
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
 * Session-aware corrections: a punch-time correction targets a specific session,
 * never writes the daily aggregate directly, survives re-aggregation, prevents
 * overlap, honors optimistic concurrency, and can create a session on a
 * materialized-absent record.
 */
class SessionCorrectionTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** Split-shift 08:00–12:00 & 16:00–20:00, overtime after 0 (UTC). */
    private function splitEmployee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Cor', 'last_name' => 'Rection', 'employment_status' => 'active',
            ]);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = [
                    'weekday' => $w, 'is_working_day' => true,
                    'segments' => [
                        ['start_time' => '08:00', 'end_time' => '12:00'],
                        ['start_time' => '16:00', 'end_time' => '20:00'],
                    ],
                ];
            }
            $schedule = app(WorkScheduleService::class)->create(
                ['name' => 'Split', 'code' => 'SPLIT', 'timezone' => 'UTC', 'grace_minutes' => 10, 'overtime_after_minutes' => 0],
                $days,
            );
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $employee->fresh();
        });
    }

    private function twoSessionDay(Tenant $tenant, Employee $employee, mixed $owner): AttendanceRecord
    {
        return $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceSettingsService::class)->update([
                'allow_multiple_sessions' => true, 'attendance_correction_enabled' => true,
            ], $owner);

            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
            app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 12:00:00', 'UTC'));
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 16:00:00', 'UTC'));

            return app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 20:00:00', 'UTC'));
        });
    }

    public function test_a_correct_second_session_checkout(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->splitEmployee($tenant);
        $reviewer = $this->makeUser();
        $record = $this->twoSessionDay($tenant, $employee, $owner);

        $this->withinTenant($tenant, function () use ($record, $owner, $reviewer) {
            $sessions = $record->sessions()->orderBy('sequence')->get();
            $s1 = $sessions[0];
            $s2 = $sessions[1];

            $correction = app(AttendanceCorrectionService::class)->request($record, [
                'attendance_session_id' => $s2->getKey(),
                'requested_check_out_at' => '2026-03-02 21:00:00',
                'reason' => 'Stayed an extra hour',
            ], $owner);

            app(AttendanceCorrectionService::class)->approve($correction, $reviewer);

            $this->assertSame('2026-03-02 12:00:00', $s1->fresh()->check_out_at->format('Y-m-d H:i:s')); // unchanged
            $this->assertSame('2026-03-02 21:00:00', $s2->fresh()->check_out_at->format('Y-m-d H:i:s'));

            $fresh = $record->fresh();
            $this->assertSame(540, $fresh->worked_minutes);   // 240 + 300
            $this->assertSame(60, $fresh->overtime_minutes);  // 21:00 vs 20:00 end
        });
    }

    public function test_b_later_session_does_not_revert_correction(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->splitEmployee($tenant);
        $reviewer = $this->makeUser();
        $record = $this->twoSessionDay($tenant, $employee, $owner);

        $this->withinTenant($tenant, function () use ($record, $owner, $reviewer) {
            $s2 = $record->sessions()->orderBy('sequence')->get()[1];
            $correction = app(AttendanceCorrectionService::class)->request($record, [
                'attendance_session_id' => $s2->getKey(),
                'requested_check_out_at' => '2026-03-02 21:00:00',
                'reason' => 'extra hour',
            ], $owner);
            app(AttendanceCorrectionService::class)->approve($correction, $reviewer);

            // A later re-aggregation must NOT revert the corrected session.
            app(AttendanceRecordAggregator::class)->aggregate($record->fresh());

            $this->assertSame('2026-03-02 21:00:00', $s2->fresh()->check_out_at->format('Y-m-d H:i:s'));
            $this->assertSame(540, $record->fresh()->worked_minutes);
        });
    }

    public function test_c_multi_session_requires_explicit_target(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->splitEmployee($tenant);
        $record = $this->twoSessionDay($tenant, $employee, $owner);

        $this->withinTenant($tenant, function () use ($record, $owner) {
            $this->expectException(ValidationException::class);
            app(AttendanceCorrectionService::class)->request($record, [
                'requested_check_out_at' => '2026-03-02 21:00:00',
                'reason' => 'ambiguous',
            ], $owner);
        });
    }

    public function test_d_overlapping_correction_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->splitEmployee($tenant);
        $reviewer = $this->makeUser();
        $record = $this->twoSessionDay($tenant, $employee, $owner);

        $this->withinTenant($tenant, function () use ($record, $owner, $reviewer) {
            $s1 = $record->sessions()->orderBy('sequence')->get()[0];
            // Extend session #1 to 17:00 → overlaps session #2 (16:00–20:00).
            $correction = app(AttendanceCorrectionService::class)->request($record, [
                'attendance_session_id' => $s1->getKey(),
                'requested_check_out_at' => '2026-03-02 17:00:00',
                'reason' => 'overlap',
            ], $owner);

            try {
                app(AttendanceCorrectionService::class)->approve($correction, $reviewer);
                $this->fail('Expected overlap rejection.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('overlap', strtolower($e->getMessage()));
            }

            $this->assertSame('2026-03-02 12:00:00', $s1->fresh()->check_out_at->format('Y-m-d H:i:s')); // unchanged
        });
    }

    public function test_e_stale_correction_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->splitEmployee($tenant);
        $reviewer = $this->makeUser();
        $record = $this->twoSessionDay($tenant, $employee, $owner);

        $this->withinTenant($tenant, function () use ($record, $owner, $reviewer) {
            $s2 = $record->sessions()->orderBy('sequence')->get()[1];
            $correction = app(AttendanceCorrectionService::class)->request($record, [
                'attendance_session_id' => $s2->getKey(),
                'requested_check_out_at' => '2026-03-02 21:00:00',
                'reason' => 'x',
            ], $owner);

            app(AttendanceRecordAggregator::class)->aggregate($record->fresh()); // version moves

            $this->expectException(ValidationException::class);
            app(AttendanceCorrectionService::class)->approve($correction, $reviewer);
        });
    }

    public function test_g_correction_on_materialized_absent_creates_session(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $reviewer = $this->makeUser();

        // Single-window schedule, employee absent, then materialized.
        $employee = $this->withinTenant($tenant, function () use ($owner) {
            app(AttendanceSettingsService::class)->update(['attendance_correction_enabled' => true], $owner);
            $emp = app(EmployeeService::class)->create([
                'first_name' => 'Abs', 'last_name' => 'Ent', 'employment_status' => 'active',
            ]);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '08:00', 'end_time' => '16:00'];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC', 'grace_minutes' => 15], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $emp->fresh();
        });

        $this->withinTenant($tenant, function () use ($employee, $owner, $reviewer) {
            app(AttendanceDayMaterializer::class)->materialize(
                CarbonImmutable::parse('2026-03-02', 'UTC'),
                CarbonImmutable::parse('2026-03-02 11:00:00', 'UTC'),
            );
            $record = AttendanceRecord::where('employee_id', $employee->getKey())->firstOrFail();
            $this->assertSame(AttendanceStatus::Absent, $record->status);
            $this->assertSame(0, $record->sessions()->count());

            $correction = app(AttendanceCorrectionService::class)->request($record, [
                'requested_check_in_at' => '2026-03-02 08:00:00',
                'requested_check_out_at' => '2026-03-02 16:00:00',
                'reason' => 'Was actually present; badge failed',
            ], $owner);

            app(AttendanceCorrectionService::class)->approve($correction, $reviewer);

            $fresh = $record->fresh();
            $this->assertSame(1, $fresh->sessions()->count());
            $this->assertFalse($fresh->is_materialized);
            $this->assertSame(480, $fresh->worked_minutes);
            $this->assertSame(AttendanceStatus::Present, $fresh->status);
        });
    }
}
