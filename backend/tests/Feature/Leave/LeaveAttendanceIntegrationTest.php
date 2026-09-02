<?php

namespace Tests\Feature\Leave;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceDayMaterializer;
use App\Modules\Attendance\Services\AttendanceSettingsService;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveEntitlementPeriodService;
use App\Modules\Leave\Services\LeavePolicyAssignmentService;
use App\Modules\Leave\Services\LeavePolicyService;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Leave\Services\LeaveTypeService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * LeaveResolver + materializer + check-in: full leave → OnLeave (no absent),
 * partial leave preserves remaining expected work and prevents false lateness.
 */
class LeaveAttendanceIntegrationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function scenario(Tenant $tenant): array
    {
        return $this->withinTenant($tenant, function () {
            // Materialization on; declare absence 60 min after (remaining) start.
            app(AttendanceSettingsService::class)->update([
                'materialization_enabled' => true, 'absence_materialize_after_minutes' => 60,
                'default_timezone' => 'UTC', 'allow_late_check_in' => true,
            ]);

            $empUser = User::factory()->create();
            $employee = app(EmployeeService::class)->create(['first_name' => 'On', 'last_name' => 'Leave', 'employment_status' => 'active']);
            $employee->fill(['user_id' => $empUser->id])->save();

            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual', 'allow_half_day' => true]);
            $policy = app(LeavePolicyService::class)->create([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'entitlement_method' => 'none', 'approval_flow' => 'none',
                'consumption_basis' => 'scheduled_minutes', 'allow_half_day' => true,
            ]);
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $period = app(LeaveEntitlementPeriodService::class)
                ->resolveOrCreate($employee->fresh(), $type->getKey(), $policy, CarbonImmutable::parse('2027-06-15'));
            $svc = app(LeaveBalanceService::class);
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 100000)));

            return [$employee->fresh(), $empUser, $type->getKey()];
        });
    }

    public function test_full_day_leave_materializes_on_leave_not_absent(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            // Approved full-day leave (flow none auto-approves).
            app(LeaveRequestService::class)->submit($employee, [
                'leave_type_id' => $typeId, 'request_kind' => 'full_day',
                'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
            ], $empUser);

            // Materialize well after the cutoff — without leave this would be absent.
            $now = CarbonImmutable::parse('2027-06-15 18:00:00', 'UTC');
            app(AttendanceDayMaterializer::class)->materializeEmployee(
                $employee, CarbonImmutable::parse('2027-06-15'), $now, app(AttendanceSettingsService::class)->current()
            );

            $record = AttendanceRecord::query()->where('employee_id', $employee->getKey())->first();
            $this->assertNotNull($record);
            $this->assertSame('on_leave', $record->status->value);
        });
    }

    public function test_partial_leave_check_in_at_remaining_start_is_not_late(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            // First-half leave: 08:00-12:00 covered; employee expected 12:00-16:00.
            app(LeaveRequestService::class)->submit($employee, [
                'leave_type_id' => $typeId, 'request_kind' => 'first_half',
                'starts_on' => '2027-06-16', 'ends_on' => '2027-06-16',
            ], $empUser);

            // Check in exactly at 12:00 → not late (remaining start is 12:00).
            $record = app(CheckInService::class)->checkIn(
                $employee, new PunchInput, null, CarbonImmutable::parse('2027-06-16 12:00:00', 'UTC')
            );

            $this->assertSame(0, (int) $record->late_minutes);
            $this->assertContains($record->status->value, ['present', 'late']);
            $this->assertSame('present', $record->status->value);
        });
    }

    public function test_partial_leave_no_punch_still_absent_for_remaining(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            app(LeaveRequestService::class)->submit($employee, [
                'leave_type_id' => $typeId, 'request_kind' => 'first_half',
                'starts_on' => '2027-06-17', 'ends_on' => '2027-06-17',
            ], $empUser);

            // After the remaining (12:00) start + cutoff, no punch → absent (not on_leave).
            $now = CarbonImmutable::parse('2027-06-17 18:00:00', 'UTC');
            app(AttendanceDayMaterializer::class)->materializeEmployee(
                $employee, CarbonImmutable::parse('2027-06-17'), $now, app(AttendanceSettingsService::class)->current()
            );

            $record = AttendanceRecord::query()->where('employee_id', $employee->getKey())
                ->whereDate('work_date', '2027-06-17')->first();
            $this->assertNotNull($record);
            $this->assertSame('absent', $record->status->value);
        });
    }
}
