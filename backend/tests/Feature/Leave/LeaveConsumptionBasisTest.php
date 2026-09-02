<?php

namespace Tests\Feature\Leave;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceDayMaterializer;
use App\Modules\Attendance\Services\AttendanceSettingsService;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequestDay;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveEntitlementPeriodService;
use App\Modules\Leave\Services\LeavePolicyAssignmentService;
use App\Modules\Leave\Services\LeavePolicyService;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Leave\Services\LeaveTypeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * D7 consumption basis: contradictory config rejected, and nominal-calendar-day
 * consumption on a NON-working day still consumes balance while attendance stays
 * a non-working (weekend) state — balance consumption ≠ attendance status.
 */
class LeaveConsumptionBasisTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_count_days_requires_nominal_basis(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual']);

            $this->expectException(ValidationException::class);
            app(LeavePolicyService::class)->create([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'consumption_basis' => 'scheduled_minutes', 'count_holidays' => true,
            ]);
        });
    }

    public function test_nominal_consumes_on_non_working_day_but_attendance_is_weekend(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            app(AttendanceSettingsService::class)->update([
                'materialization_enabled' => true, 'absence_materialize_after_minutes' => 60, 'default_timezone' => 'UTC',
            ]);

            $empUser = User::factory()->create();
            $employee = app(EmployeeService::class)->create(['first_name' => 'No', 'last_name' => 'Min', 'employment_status' => 'active']);
            $employee->fill(['user_id' => $empUser->id])->save();

            // A schedule where EVERY day is non-working (so any date is an off day).
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => false];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $type = app(LeaveTypeService::class)->create(['code' => 'UNP', 'name' => 'Calendar leave']);
            $policy = app(LeavePolicyService::class)->create([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'entitlement_method' => 'none', 'approval_flow' => 'none',
                'consumption_basis' => 'nominal_calendar_day', 'nominal_day_minutes' => 480,
                'count_non_working_days' => true,
            ]);
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $period = app(LeaveEntitlementPeriodService::class)
                ->resolveOrCreate($employee->fresh(), $type->getKey(), $policy, CarbonImmutable::parse('2027-06-15'));
            $svc = app(LeaveBalanceService::class);
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 4800)));

            app(LeaveRequestService::class)->submit($employee->fresh(), [
                'leave_type_id' => $type->getKey(), 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
            ], $empUser);

            // Consumption is the nominal 480; coverage is 0 (no expected work).
            $day = LeaveRequestDay::query()->first();
            $this->assertSame(480, $day->consumption_minutes);
            $this->assertSame(0, $day->coverage_minutes);
            $this->assertSame(480, LeaveBalance::query()->first()->used_minutes);

            // Attendance still classifies the day as weekend/off — NOT on_leave.
            $now = CarbonImmutable::parse('2027-06-15 18:00:00', 'UTC');
            app(AttendanceDayMaterializer::class)->materializeEmployee(
                $employee, CarbonImmutable::parse('2027-06-15'), $now, app(AttendanceSettingsService::class)->current()
            );
            $record = AttendanceRecord::query()->where('employee_id', $employee->getKey())->first();
            $this->assertSame('weekend', $record->status->value);
        });
    }
}
