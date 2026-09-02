<?php

namespace Tests\Feature\Leave;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveApprovalService;
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

class LeaveRequestLifecycleTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0:Employee,1:User,2:string,3:string} employee, empUser, typeId, policyId */
    private function scenario(Tenant $tenant, string $approvalFlow = 'manager', array $policyOverrides = [], int $grant = 4800): array
    {
        return $this->withinTenant($tenant, function () use ($approvalFlow, $policyOverrides, $grant) {
            $empUser = User::factory()->create();
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Req', 'last_name' => 'Uestor', 'employment_status' => 'active',
            ]);
            $employee->fill(['user_id' => $empUser->id])->save();

            // Company schedule: every day 08:00-16:00 (480 min).
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual', 'allow_half_day' => true]);
            $policy = app(LeavePolicyService::class)->create(array_merge([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'entitlement_method' => 'none', 'approval_flow' => $approvalFlow,
                'consumption_basis' => 'scheduled_minutes', 'allow_half_day' => true,
            ], $policyOverrides));
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            // Seed balance in the request's calendar-year period.
            $period = app(LeaveEntitlementPeriodService::class)
                ->resolveOrCreate($employee->fresh(), $type->getKey(), $policy, CarbonImmutable::parse('2027-06-15'));
            $svc = app(LeaveBalanceService::class);
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, $grant)));

            return [$employee->fresh(), $empUser, $type->getKey(), $policy->getKey()];
        });
    }

    public function test_submit_reserves_then_approval_converts_to_usage(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $owner, $typeId) {
            $request = app(LeaveRequestService::class)->submit($employee, [
                'leave_type_id' => $typeId, 'request_kind' => 'full_day',
                'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
            ], $empUser);

            $this->assertSame('pending', $request->status->value);
            $this->assertSame(480, $request->requested_consumption_minutes);
            $this->assertSame(4320, LeaveBalance::query()->first()->available_minutes);
            $this->assertSame(480, LeaveBalance::query()->first()->reserved_minutes);

            // Owner approves (single HR-pool step, employee has no manager).
            app(LeaveApprovalService::class)->approve($request->fresh(), $owner);

            $b = LeaveBalance::query()->first();
            $this->assertSame('approved', $request->fresh()->status->value);
            $this->assertSame(0, $b->reserved_minutes);
            $this->assertSame(480, $b->used_minutes);
            $this->assertSame(4320, $b->available_minutes); // deducted exactly once
        });
    }

    public function test_self_approval_is_forbidden(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            $request = app(LeaveRequestService::class)->submit($employee, [
                'leave_type_id' => $typeId, 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
            ], $empUser);

            $this->expectException(ValidationException::class);
            app(LeaveApprovalService::class)->approve($request->fresh(), $empUser);
        });
    }

    public function test_overlap_rejected_but_two_halves_coexist(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant);
        $svc = fn () => app(LeaveRequestService::class);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            $s = app(LeaveRequestService::class);
            $s->submit($employee, ['leave_type_id' => $typeId, 'request_kind' => 'first_half', 'starts_on' => '2027-06-16', 'ends_on' => '2027-06-16'], $empUser);
            // Second half same day is allowed (no coverage overlap).
            $s->submit($employee, ['leave_type_id' => $typeId, 'request_kind' => 'second_half', 'starts_on' => '2027-06-16', 'ends_on' => '2027-06-16'], $empUser);

            // A full day overlapping the covered halves is rejected.
            $this->expectException(ValidationException::class);
            $s->submit($employee, ['leave_type_id' => $typeId, 'request_kind' => 'full_day', 'starts_on' => '2027-06-16', 'ends_on' => '2027-06-16'], $empUser);
        });
    }

    public function test_insufficient_balance_blocks_submission(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant, 'manager', [], 240);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            $this->expectException(ValidationException::class);
            app(LeaveRequestService::class)->submit($employee, [
                'leave_type_id' => $typeId, 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
            ], $empUser);
        });
    }

    public function test_withdraw_releases_reservation(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            $s = app(LeaveRequestService::class);
            $request = $s->submit($employee, ['leave_type_id' => $typeId, 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15'], $empUser);
            $this->assertSame(480, LeaveBalance::query()->first()->reserved_minutes);

            $s->withdraw($request->fresh(), $empUser);

            $b = LeaveBalance::query()->first();
            $this->assertSame('withdrawn', LeaveRequest::query()->first()->status->value);
            $this->assertSame(0, $b->reserved_minutes);
            $this->assertSame(4800, $b->available_minutes);
        });
    }

    public function test_none_flow_auto_approves_on_submit(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant, 'none');

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            $request = app(LeaveRequestService::class)->submit($employee, [
                'leave_type_id' => $typeId, 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
            ], $empUser);

            $b = LeaveBalance::query()->first();
            $this->assertSame('approved', $request->fresh()->status->value);
            $this->assertSame(0, $b->reserved_minutes);
            $this->assertSame(480, $b->used_minutes);
        });
    }
}
