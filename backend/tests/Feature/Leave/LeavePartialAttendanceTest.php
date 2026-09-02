<?php

namespace Tests\Feature\Leave;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceDayMaterializer;
use App\Modules\Attendance\Services\AttendanceSettingsService;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\CheckOutService;
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
 * Partial-leave attendance: check-out early-leave and materializer cutoff use the
 * REMAINING expected work, not the original shift. Straight and split shifts.
 */
class LeavePartialAttendanceTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function scenario(Tenant $tenant, array $days): array
    {
        return $this->withinTenant($tenant, function () use ($days) {
            app(AttendanceSettingsService::class)->update([
                'materialization_enabled' => true, 'absence_materialize_after_minutes' => 60,
                'default_timezone' => 'UTC', 'allow_late_check_in' => true, 'allow_multiple_sessions' => true,
            ]);
            $empUser = User::factory()->create();
            $employee = app(EmployeeService::class)->create(['first_name' => 'Par', 'last_name' => 'Tial', 'employment_status' => 'active']);
            $employee->fill(['user_id' => $empUser->id])->save();
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual', 'allow_half_day' => true]);
            $policy = app(LeavePolicyService::class)->create([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'entitlement_method' => 'none', 'approval_flow' => 'none', 'allow_half_day' => true,
            ]);
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $period = app(LeaveEntitlementPeriodService::class)->resolveOrCreate($employee->fresh(), $type->getKey(), $policy, CarbonImmutable::parse('2027-06-15'));
            $svc = app(LeaveBalanceService::class);
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 100000)));

            return [$employee->fresh(), $empUser, $type->getKey()];
        });
    }

    private function straight(): array
    {
        $d = [];
        for ($w = 0; $w <= 6; $w++) {
            $d[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
        }

        return $d;
    }

    private function split(): array
    {
        $d = [];
        for ($w = 0; $w <= 6; $w++) {
            $d[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [
                ['start_time' => '08:00', 'end_time' => '12:00'],
                ['start_time' => '16:00', 'end_time' => '20:00'],
            ]];
        }

        return $d;
    }

    public function test_second_half_leave_checkout_at_noon_has_no_early_leave(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant, $this->straight());

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            // Leave 12:00-16:00; expected work 08:00-12:00.
            app(LeaveRequestService::class)->submit($employee, ['leave_type_id' => $typeId, 'request_kind' => 'second_half', 'starts_on' => '2027-06-16', 'ends_on' => '2027-06-16'], $empUser);

            app(CheckInService::class)->checkIn($employee, new PunchInput, null, CarbonImmutable::parse('2027-06-16 08:00:00', 'UTC'));
            $record = app(CheckOutService::class)->checkOut($employee, new PunchInput, null, CarbonImmutable::parse('2027-06-16 12:00:00', 'UTC'));

            $this->assertSame(0, (int) $record->early_leave_minutes);
            $this->assertSame(0, (int) $record->late_minutes);
        });
    }

    public function test_first_half_leave_check_in_noon_checkout_four_pm_clean(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant, $this->straight());

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            app(LeaveRequestService::class)->submit($employee, ['leave_type_id' => $typeId, 'request_kind' => 'first_half', 'starts_on' => '2027-06-17', 'ends_on' => '2027-06-17'], $empUser);

            app(CheckInService::class)->checkIn($employee, new PunchInput, null, CarbonImmutable::parse('2027-06-17 12:00:00', 'UTC'));
            $record = app(CheckOutService::class)->checkOut($employee, new PunchInput, null, CarbonImmutable::parse('2027-06-17 16:00:00', 'UTC'));

            $this->assertSame(0, (int) $record->late_minutes);
            $this->assertSame(0, (int) $record->early_leave_minutes);
        });
    }

    public function test_split_shift_first_half_leave_second_segment_clean(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant, $this->split());

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            // First half covers the 08:00-12:00 segment; employee works 16:00-20:00.
            app(LeaveRequestService::class)->submit($employee, ['leave_type_id' => $typeId, 'request_kind' => 'first_half', 'starts_on' => '2027-06-18', 'ends_on' => '2027-06-18'], $empUser);

            app(CheckInService::class)->checkIn($employee, new PunchInput, null, CarbonImmutable::parse('2027-06-18 16:00:00', 'UTC'));
            $record = app(CheckOutService::class)->checkOut($employee, new PunchInput, null, CarbonImmutable::parse('2027-06-18 20:00:00', 'UTC'));

            $this->assertSame(0, (int) $record->late_minutes);
            $this->assertSame(0, (int) $record->early_leave_minutes);
            $this->assertNotSame('on_leave', $record->status->value);
        });
    }

    public function test_materializer_cutoff_uses_remaining_start(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant, $this->straight());

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            // Leave 08:00-12:00; remaining expected 12:00-16:00; cutoff = 13:00.
            app(LeaveRequestService::class)->submit($employee, ['leave_type_id' => $typeId, 'request_kind' => 'first_half', 'starts_on' => '2027-06-19', 'ends_on' => '2027-06-19'], $empUser);
            $settings = app(AttendanceSettingsService::class)->current();

            // Before the remaining cutoff (12:45) → NOT absent (would be if measured from 08:00).
            app(AttendanceDayMaterializer::class)->materializeEmployee($employee, CarbonImmutable::parse('2027-06-19'), CarbonImmutable::parse('2027-06-19 12:45:00', 'UTC'), $settings);
            $this->assertNull(AttendanceRecord::query()->where('employee_id', $employee->getKey())->whereDate('work_date', '2027-06-19')->first());

            // After the remaining cutoff (13:30) → absent for the uncovered work.
            app(AttendanceDayMaterializer::class)->materializeEmployee($employee, CarbonImmutable::parse('2027-06-19'), CarbonImmutable::parse('2027-06-19 13:30:00', 'UTC'), $settings);
            $record = AttendanceRecord::query()->where('employee_id', $employee->getKey())->whereDate('work_date', '2027-06-19')->first();
            $this->assertSame('absent', $record->status->value);
        });
    }
}
