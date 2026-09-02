<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceCorrectionService;
use App\Modules\Attendance\Services\AttendanceRecordAggregator;
use App\Modules\Attendance\Services\ManualAttendanceService;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Manual entry and the controlled correction workflow: request -> review, with
 * NO self-approval (segregation of duties) and a full recompute from snapshot.
 */
class CorrectionAndManualTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function scheduledEmployee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'M', 'last_name' => 'N', 'employment_status' => 'active',
            ]);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '08:00', 'end_time' => '16:00'];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $employee;
        });
    }

    public function test_manual_entry_computes_minutes(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->scheduledEmployee($tenant);

        $record = $this->withinTenant($tenant, fn () => app(ManualAttendanceService::class)->record(
            $employee,
            ['check_in_at' => '2026-03-02 08:00:00', 'check_out_at' => '2026-03-02 16:00:00', 'reason' => 'missed punch'],
            $owner,
        ));

        $this->assertTrue($record->is_manual);
        $this->assertSame(480, $record->worked_minutes);
    }

    public function test_correction_requires_a_different_reviewer(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->scheduledEmployee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $employee) {
            $record = app(ManualAttendanceService::class)->record(
                $employee,
                ['check_in_at' => '2026-03-02 09:00:00', 'check_out_at' => '2026-03-02 16:00:00', 'reason' => 'x'],
                $owner,
            );

            $correction = app(AttendanceCorrectionService::class)->request(
                $record,
                ['requested_check_in_at' => '2026-03-02 08:00:00', 'reason' => 'was actually on time'],
                $owner,
            );

            // Same person cannot approve their own request.
            try {
                app(AttendanceCorrectionService::class)->approve($correction, $owner);
                $this->fail('Expected self-approval to be rejected.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('your own', strtolower($e->getMessage()));
            }

            $this->assertSame(CorrectionStatus::Pending, $correction->fresh()->status);
        });
    }

    public function test_stale_correction_is_rejected_when_record_changed(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $reviewer = $this->memberWithRole($tenant, 'hr-manager');
        $employee = $this->scheduledEmployee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $reviewer, $employee) {
            $record = app(ManualAttendanceService::class)->record(
                $employee,
                ['check_in_at' => '2026-03-02 09:00:00', 'check_out_at' => '2026-03-02 16:00:00', 'reason' => 'x'],
                $owner,
            );

            $correction = app(AttendanceCorrectionService::class)->request(
                $record,
                ['requested_check_in_at' => '2026-03-02 08:00:00', 'reason' => 'on time'],
                $owner,
            );

            // The record moves on after the request (a re-aggregation bumps version).
            app(AttendanceRecordAggregator::class)->aggregate($record->fresh());

            $this->expectException(ValidationException::class);
            app(AttendanceCorrectionService::class)->approve($correction, $reviewer);
        });
    }

    public function test_correction_approved_by_other_recomputes_record(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $reviewer = $this->memberWithRole($tenant, 'hr-manager');
        $employee = $this->scheduledEmployee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $reviewer, $employee) {
            $record = app(ManualAttendanceService::class)->record(
                $employee,
                ['check_in_at' => '2026-03-02 09:00:00', 'check_out_at' => '2026-03-02 16:00:00', 'reason' => 'x'],
                $owner,
            );
            $this->assertSame(45, $record->late_minutes); // 09:00, grace 15

            $correction = app(AttendanceCorrectionService::class)->request(
                $record,
                ['requested_check_in_at' => '2026-03-02 08:00:00', 'reason' => 'on time'],
                $owner,
            );

            $approved = app(AttendanceCorrectionService::class)->approve($correction, $reviewer);
            $this->assertSame(CorrectionStatus::Approved, $approved->status);

            $fresh = AttendanceRecord::find($record->id);
            $this->assertSame(0, $fresh->late_minutes);      // recomputed from 08:00
            $this->assertNotNull($fresh->corrected_at);
        });
    }
}
