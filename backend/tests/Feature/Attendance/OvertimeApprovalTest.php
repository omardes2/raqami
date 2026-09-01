<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\OvertimeApproval;
use App\Modules\Attendance\Services\AttendanceSettingsService;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\CheckOutService;
use App\Modules\Attendance\Services\OvertimeApprovalService;
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
 * Overtime approval: raw calculated_minutes stays separate from approved_minutes,
 * no self-approval, no over-approval without override, and optimistic concurrency.
 */
class OvertimeApprovalTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant, array $attrs = []): Employee
    {
        return $this->withinTenant($tenant, function () use ($attrs) {
            $employee = app(EmployeeService::class)->create(array_merge([
                'first_name' => 'Rami', 'last_name' => 'T', 'employment_status' => 'active',
            ], $attrs));

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
                ['name' => 'Std', 'code' => 'STD', 'timezone' => 'UTC', 'grace_minutes' => 15, 'overtime_after_minutes' => 0],
                $days,
            );
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $employee->fresh();
        });
    }

    private function enableOvertime(Tenant $tenant, mixed $owner, bool $requiresApproval = true, bool $autoApprove = false): void
    {
        $this->withinTenant($tenant, fn () => app(AttendanceSettingsService::class)->update([
            'overtime_tracking_enabled' => true,
            'overtime_requires_approval' => $requiresApproval,
            'overtime_auto_approve' => $autoApprove,
            'allow_late_check_in' => true,
        ], $owner));
    }

    /** Work 08:00–18:00 → 120 raw overtime minutes on a 08:00–16:00 schedule. */
    private function workOvertime(Tenant $tenant, Employee $employee, mixed $owner): AttendanceRecord
    {
        return $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));

            return app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 18:00:00', 'UTC'));
        });
    }

    public function test_overtime_produces_pending_approval(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);
        $this->enableOvertime($tenant, $owner);

        $record = $this->workOvertime($tenant, $employee, $owner);

        $this->withinTenant($tenant, function () use ($record) {
            $this->assertSame(120, $record->overtime_minutes);
            $approval = OvertimeApproval::where('attendance_record_id', $record->getKey())->first();
            $this->assertNotNull($approval);
            $this->assertSame(OvertimeStatus::Pending, $approval->status);
            $this->assertSame(120, $approval->calculated_minutes);
            $this->assertNull($approval->approved_minutes);
        });
    }

    public function test_auto_approve_marks_approved(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);
        $this->enableOvertime($tenant, $owner, requiresApproval: true, autoApprove: true);

        $record = $this->workOvertime($tenant, $employee, $owner);

        $this->withinTenant($tenant, function () use ($record) {
            $approval = OvertimeApproval::where('attendance_record_id', $record->getKey())->first();
            $this->assertSame(OvertimeStatus::Approved, $approval->status);
            $this->assertSame(120, $approval->approved_minutes);
        });
    }

    public function test_reviewer_can_approve_and_reduce_minutes(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);
        $this->enableOvertime($tenant, $owner);
        $record = $this->workOvertime($tenant, $employee, $owner);
        $reviewer = $this->makeUser();

        $this->withinTenant($tenant, function () use ($record, $reviewer) {
            $approval = OvertimeApproval::where('attendance_record_id', $record->getKey())->first();
            $approved = app(OvertimeApprovalService::class)->approve($approval, $reviewer, 90);

            $this->assertSame(OvertimeStatus::Approved, $approved->status);
            $this->assertSame(90, $approved->approved_minutes);
            $this->assertSame(120, $approved->calculated_minutes); // raw preserved
        });
    }

    public function test_cannot_approve_more_than_calculated_without_override(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);
        $this->enableOvertime($tenant, $owner);
        $record = $this->workOvertime($tenant, $employee, $owner);
        $reviewer = $this->makeUser();

        $this->withinTenant($tenant, function () use ($record, $reviewer) {
            $approval = OvertimeApproval::where('attendance_record_id', $record->getKey())->first();
            $this->expectException(ValidationException::class);
            app(OvertimeApprovalService::class)->approve($approval, $reviewer, 200);
        });
    }

    public function test_employee_cannot_self_approve(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $selfUser = $this->makeUser();
        $employee = $this->employee($tenant, ['user_id' => $selfUser->id]);
        $this->enableOvertime($tenant, $owner);
        $record = $this->workOvertime($tenant, $employee, $owner);

        $this->withinTenant($tenant, function () use ($record, $selfUser) {
            $approval = OvertimeApproval::where('attendance_record_id', $record->getKey())->first();
            $this->expectException(ValidationException::class);
            app(OvertimeApprovalService::class)->approve($approval, $selfUser);
        });
    }

    public function test_stale_record_version_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);
        $this->enableOvertime($tenant, $owner);
        $record = $this->workOvertime($tenant, $employee, $owner);
        $reviewer = $this->makeUser();

        $this->withinTenant($tenant, function () use ($record, $reviewer) {
            $approval = OvertimeApproval::where('attendance_record_id', $record->getKey())->first();
            // Reviewer saw an older version than the record now carries.
            $this->expectException(ValidationException::class);
            app(OvertimeApprovalService::class)->approve($approval, $reviewer, null, null, false, $record->version - 1);
        });
    }

    public function test_terminal_approval_cannot_be_reviewed_again(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);
        $this->enableOvertime($tenant, $owner);
        $record = $this->workOvertime($tenant, $employee, $owner);
        $reviewer = $this->makeUser();

        $this->withinTenant($tenant, function () use ($record, $reviewer) {
            $approval = OvertimeApproval::where('attendance_record_id', $record->getKey())->first();
            app(OvertimeApprovalService::class)->approve($approval, $reviewer);

            $this->expectException(ValidationException::class);
            app(OvertimeApprovalService::class)->reject($approval->fresh(), $reviewer);
        });
    }
}
