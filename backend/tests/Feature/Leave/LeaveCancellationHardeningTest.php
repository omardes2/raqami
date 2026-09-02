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
use App\Modules\Leave\Services\LeaveCancellationService;
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
 * Cancellation accounting: only FUTURE (not-yet-elapsed) usage is reversed, the
 * reversal happens exactly once (a repeat cancel is refused), and a request in
 * cancellation_pending remains attendance-active (materializes on_leave).
 */
class LeaveCancellationHardeningTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function scenario(Tenant $tenant, string $flow = 'none'): array
    {
        return $this->withinTenant($tenant, function () use ($flow) {
            app(AttendanceSettingsService::class)->update([
                'materialization_enabled' => true, 'absence_materialize_after_minutes' => 60, 'default_timezone' => 'UTC',
            ]);
            $empUser = User::factory()->create();
            $employee = app(EmployeeService::class)->create(['first_name' => 'Ca', 'last_name' => 'Nc', 'employment_status' => 'active']);
            $employee->fill(['user_id' => $empUser->id])->save();
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual']);
            $policy = app(LeavePolicyService::class)->create([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'entitlement_method' => 'none', 'approval_flow' => $flow,
            ]);
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $period = app(LeaveEntitlementPeriodService::class)->resolveOrCreate($employee->fresh(), $type->getKey(), $policy, CarbonImmutable::parse('2027-06-03'));
            $svc = app(LeaveBalanceService::class);
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 100000)));

            return [$employee->fresh(), $empUser, $type->getKey()];
        });
    }

    public function test_partially_elapsed_cancellation_reverses_only_future_and_once(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-06-03 09:00:00', 'UTC'));
        [$employee, $empUser, $typeId] = $this->scenario($tenant);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $owner, $typeId) {
            // Approved Jun 1-5 (5 * 480 = 2400 used). "Today" is Jun 3.
            $request = app(LeaveRequestService::class)->submit($employee, [
                'leave_type_id' => $typeId, 'starts_on' => '2027-06-01', 'ends_on' => '2027-06-05',
            ], $empUser);
            $this->assertSame('approved', $request->fresh()->status->value);
            $this->assertSame(2400, LeaveBalance::query()->first()->used_minutes);

            // Direct cancel on Jun 3 → reverse ONLY Jun 3,4,5 (>= today) = 1440.
            app(LeaveCancellationService::class)->directCancel($request->fresh(), $owner, 'plans changed');

            $used = LeaveBalance::query()->first()->used_minutes;
            $this->assertSame(960, $used); // Jun 1-2 (elapsed) NOT refunded

            // Historical request-day snapshots remain intact.
            $this->assertSame(5, LeaveRequestDay::query()->where('leave_request_id', $request->getKey())->count());

            // Repeat cancellation cannot duplicate the reversal.
            $this->expectException(ValidationException::class);
            app(LeaveCancellationService::class)->directCancel($request->fresh(), $owner, 'again');
        });

        // used unchanged after the refused second cancel.
        $this->withinTenant($tenant, fn () => $this->assertSame(960, LeaveBalance::query()->first()->used_minutes));
    }

    public function test_cancellation_pending_still_materializes_on_leave(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            $request = app(LeaveRequestService::class)->submit($employee, [
                'leave_type_id' => $typeId, 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
            ], $empUser);
            $request = app(LeaveCancellationService::class)->request($request->fresh(), $empUser);
            $this->assertSame('cancellation_pending', $request->status->value);

            // Attendance still leave-adjusted while cancellation is pending.
            app(AttendanceDayMaterializer::class)->materializeEmployee(
                $employee, CarbonImmutable::parse('2027-06-15'), CarbonImmutable::parse('2027-06-15 18:00:00', 'UTC'),
                app(AttendanceSettingsService::class)->current()
            );
            $record = AttendanceRecord::query()->where('employee_id', $employee->getKey())->whereDate('work_date', '2027-06-15')->first();
            $this->assertSame('on_leave', $record->status->value);
        });
    }
}
