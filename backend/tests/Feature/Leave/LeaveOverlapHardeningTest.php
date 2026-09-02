<?php

namespace Tests\Feature\Leave;

use App\Modules\Attendance\Services\HolidayCalendarService;
use App\Modules\Attendance\Services\WorkScheduleService;
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
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * BLOCKER-1 hardening: nominal-calendar-day leave (zero coverage, positive
 * consumption) cannot be consumed twice on the same logical date — same type,
 * different type, or on a holiday. Plus: a half-day on a zero-schedule day is
 * rejected, and the same scheduled half cannot be double-booked.
 */
class LeaveOverlapHardeningTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant, array $days): array
    {
        return $this->withinTenant($tenant, function () use ($days) {
            $empUser = User::factory()->create();
            $employee = app(EmployeeService::class)->create(['first_name' => 'Ov', 'last_name' => 'Lap', 'employment_status' => 'active']);
            $employee->fill(['user_id' => $empUser->id])->save();
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return [$employee->fresh(), $empUser];
        });
    }

    private function nominalType(string $code, bool $countHolidays = false, bool $countNonWorking = true): string
    {
        $type = app(LeaveTypeService::class)->create(['code' => $code, 'name' => $code]);
        $policy = app(LeavePolicyService::class)->create([
            'leave_type_id' => $type->getKey(), 'name' => $code, 'effective_from' => '2026-01-01',
            'entitlement_method' => 'none', 'approval_flow' => 'none',
            'consumption_basis' => 'nominal_calendar_day', 'nominal_day_minutes' => 480,
            'count_holidays' => $countHolidays, 'count_non_working_days' => $countNonWorking,
        ]);
        app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

        return $type->getKey();
    }

    private function grant(Employee $employee, string $typeId): void
    {
        $period = app(LeaveEntitlementPeriodService::class)
            ->resolveOrCreate($employee, $typeId, null, CarbonImmutable::parse('2027-06-19'));
        $svc = app(LeaveBalanceService::class);
        DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 100000)));
    }

    private function offDays(): array
    {
        $days = [];
        for ($w = 0; $w <= 6; $w++) {
            $days[] = ['weekday' => $w, 'is_working_day' => false];
        }

        return $days;
    }

    private function workingDays(): array
    {
        $days = [];
        for ($w = 0; $w <= 6; $w++) {
            $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
        }

        return $days;
    }

    public function test_nominal_same_type_duplicate_same_date_blocked(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser] = $this->employee($tenant, $this->offDays());

        $this->withinTenant($tenant, function () use ($employee, $empUser) {
            $typeId = $this->nominalType('CAL');
            $this->grant($employee, $typeId);
            $s = app(LeaveRequestService::class);
            $s->submit($employee, ['leave_type_id' => $typeId, 'starts_on' => '2027-06-19', 'ends_on' => '2027-06-19'], $empUser);

            $this->expectException(ValidationException::class);
            $s->submit($employee, ['leave_type_id' => $typeId, 'starts_on' => '2027-06-19', 'ends_on' => '2027-06-19'], $empUser);
        });
    }

    public function test_nominal_different_type_same_date_blocked(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser] = $this->employee($tenant, $this->offDays());

        $this->withinTenant($tenant, function () use ($employee, $empUser) {
            $a = $this->nominalType('CALA');
            $b = $this->nominalType('CALB');
            $this->grant($employee, $a);
            $this->grant($employee, $b);
            $s = app(LeaveRequestService::class);
            $s->submit($employee, ['leave_type_id' => $a, 'starts_on' => '2027-06-19', 'ends_on' => '2027-06-19'], $empUser);

            $this->expectException(ValidationException::class);
            $s->submit($employee, ['leave_type_id' => $b, 'starts_on' => '2027-06-19', 'ends_on' => '2027-06-19'], $empUser);
        });
    }

    public function test_nominal_holiday_duplicate_blocked(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser] = $this->employee($tenant, $this->workingDays());

        $this->withinTenant($tenant, function () use ($employee, $empUser) {
            $cal = app(HolidayCalendarService::class)->createCalendar(['name' => 'Nat', 'code' => 'NAT']);
            app(HolidayCalendarService::class)->addHoliday($cal, ['name' => 'Holiday', 'date' => '2027-06-21']);
            app(HolidayCalendarService::class)->assign($cal, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $typeId = $this->nominalType('CALH', countHolidays: true, countNonWorking: false);
            $this->grant($employee, $typeId);
            $s = app(LeaveRequestService::class);
            $s->submit($employee, ['leave_type_id' => $typeId, 'starts_on' => '2027-06-21', 'ends_on' => '2027-06-21'], $empUser);

            $this->expectException(ValidationException::class);
            $s->submit($employee, ['leave_type_id' => $typeId, 'starts_on' => '2027-06-21', 'ends_on' => '2027-06-21'], $empUser);
        });
    }

    public function test_zero_schedule_half_day_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser] = $this->employee($tenant, $this->offDays());

        $this->withinTenant($tenant, function () use ($employee, $empUser) {
            $typeId = $this->nominalType('CALHALF');
            $this->grant($employee, $typeId);

            $this->expectException(ValidationException::class);
            app(LeaveRequestService::class)->submit($employee, [
                'leave_type_id' => $typeId, 'request_kind' => 'first_half',
                'starts_on' => '2027-06-19', 'ends_on' => '2027-06-19',
            ], $empUser);
        });
    }

    public function test_same_scheduled_half_twice_blocked(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser] = $this->employee($tenant, $this->workingDays());

        $this->withinTenant($tenant, function () use ($employee, $empUser) {
            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual', 'allow_half_day' => true]);
            $policy = app(LeavePolicyService::class)->create([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'entitlement_method' => 'none', 'approval_flow' => 'none', 'allow_half_day' => true,
            ]);
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);
            $this->grant($employee, $type->getKey());
            $s = app(LeaveRequestService::class);
            $s->submit($employee, ['leave_type_id' => $type->getKey(), 'request_kind' => 'first_half', 'starts_on' => '2027-06-16', 'ends_on' => '2027-06-16'], $empUser);

            $this->expectException(ValidationException::class);
            $s->submit($employee, ['leave_type_id' => $type->getKey(), 'request_kind' => 'first_half', 'starts_on' => '2027-06-16', 'ends_on' => '2027-06-16'], $empUser);
        });
    }
}
