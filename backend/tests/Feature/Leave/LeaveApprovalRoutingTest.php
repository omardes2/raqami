<?php

namespace Tests\Feature\Leave;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\ApprovalStepType;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveRequestApproval;
use App\Modules\Leave\Services\LeaveApprovalService;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveEntitlementPeriodService;
use App\Modules\Leave\Services\LeavePolicyAssignmentService;
use App\Modules\Leave\Services\LeavePolicyService;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Leave\Services\LeaveTypeService;
use App\Modules\Organization\Models\Department;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Approval routing (D2): the snapshotted `manager` flow resolves through
 * direct_manager → department_manager → HR pool, skips a self-approver, never
 * routes to a Team Lead automatically, blocks self-approval even at the HR pool,
 * runs a two-step Manager→HR flow, and never double-converts the reservation.
 */
class LeaveApprovalRoutingTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** Create a linked (user + employee) worker in the tenant. */
    private function worker(string $first): array
    {
        $user = User::factory()->create();
        $employee = app(EmployeeService::class)->create(['first_name' => $first, 'last_name' => 'X', 'employment_status' => 'active']);
        $employee->fill(['user_id' => $user->id])->save();

        return [$employee->fresh(), $user];
    }

    /** Company schedule + annual type/policy (given flow) + a large grant. */
    private function seedLeave(Tenant $tenant, string $flow, Employee $employee): string
    {
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

        $period = app(LeaveEntitlementPeriodService::class)->resolveOrCreate($employee->fresh(), $type->getKey(), $policy, CarbonImmutable::parse('2027-06-15'));
        $svc = app(LeaveBalanceService::class);
        DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 100000)));

        return $type->getKey();
    }

    private function submit(Employee $employee, User $empUser, string $typeId): LeaveRequest
    {
        return app(LeaveRequestService::class)->submit($employee, [
            'leave_type_id' => $typeId, 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
        ], $empUser);
    }

    private function stepTypes(LeaveRequest $request): array
    {
        return LeaveRequestApproval::query()
            ->where('leave_request_id', $request->getKey())
            ->where('purpose', 'approval')
            ->orderBy('step_order')
            ->get()
            ->map(fn ($s) => $s->approver_type->value)
            ->all();
    }

    public function test_manager_flow_routes_to_direct_manager(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($tenant) {
            [$manager, $mgrUser] = $this->worker('Man');
            [$employee, $empUser] = $this->worker('Emp');
            $employee->fill(['direct_manager_employee_id' => $manager->getKey()])->save();
            $typeId = $this->seedLeave($tenant, 'manager', $employee->fresh());

            $request = $this->submit($employee->fresh(), $empUser, $typeId);
            $this->assertSame([ApprovalStepType::DirectManager->value], $this->stepTypes($request));

            $step = LeaveRequestApproval::query()->where('leave_request_id', $request->getKey())->first();
            $this->assertSame((string) $mgrUser->id, (string) $step->approver_user_id);

            app(LeaveApprovalService::class)->approve($request->fresh(), $mgrUser);
            $this->assertSame('approved', $request->fresh()->status->value);
            $this->assertSame(480, LeaveBalance::query()->first()->used_minutes);
        });
    }

    public function test_manager_flow_falls_back_to_department_manager(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($tenant) {
            [$deptMgr, $deptMgrUser] = $this->worker('Dep');
            $department = Department::factory()->create(['manager_employee_id' => $deptMgr->getKey()]);
            [$employee, $empUser] = $this->worker('Emp');
            // No direct manager; department has a manager.
            $employee->fill(['department_id' => $department->getKey()])->save();
            $typeId = $this->seedLeave($tenant, 'manager', $employee->fresh());

            $request = $this->submit($employee->fresh(), $empUser, $typeId);
            $this->assertSame([ApprovalStepType::DepartmentManager->value], $this->stepTypes($request));

            app(LeaveApprovalService::class)->approve($request->fresh(), $deptMgrUser);
            $this->assertSame('approved', $request->fresh()->status->value);
        });
    }

    public function test_manager_flow_falls_back_to_hr_pool_when_no_manager(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($tenant, $owner) {
            [$employee, $empUser] = $this->worker('Emp');
            $typeId = $this->seedLeave($tenant, 'manager', $employee->fresh());

            $request = $this->submit($employee->fresh(), $empUser, $typeId);
            // No manager, no department manager, no automatic Team Lead → HR pool.
            $this->assertSame([ApprovalStepType::HrPool->value], $this->stepTypes($request));

            $step = LeaveRequestApproval::query()->where('leave_request_id', $request->getKey())->first();
            $this->assertNull($step->approver_user_id); // pool carries no directory user

            // The owner (holds leave.approve org-wide) may act on the pool step.
            app(LeaveApprovalService::class)->approve($request->fresh(), $owner);
            $this->assertSame('approved', $request->fresh()->status->value);
        });
    }

    public function test_manager_who_is_the_requester_is_skipped_to_department(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($tenant) {
            [$deptMgr, $deptMgrUser] = $this->worker('Dep');
            $department = Department::factory()->create(['manager_employee_id' => $deptMgr->getKey()]);
            [$employee, $empUser] = $this->worker('Emp');
            // Employee is their OWN direct manager → cannot self-approve → skip.
            $employee->fill([
                'direct_manager_employee_id' => $employee->getKey(),
                'department_id' => $department->getKey(),
            ])->save();
            $typeId = $this->seedLeave($tenant, 'manager', $employee->fresh());

            $request = $this->submit($employee->fresh(), $empUser, $typeId);
            $this->assertSame([ApprovalStepType::DepartmentManager->value], $this->stepTypes($request));
        });
    }

    public function test_self_approval_blocked_even_on_hr_pool_step(): void
    {
        // Owner is also an employee submitting their own leave; the pool step is
        // actionable by pool holders, but never by the requester themselves.
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($tenant, $owner) {
            $employee = app(EmployeeService::class)->create(['first_name' => 'Own', 'last_name' => 'Er', 'employment_status' => 'active']);
            $employee->fill(['user_id' => $owner->id])->save();
            $typeId = $this->seedLeave($tenant, 'hr', $employee->fresh());

            $request = $this->submit($employee->fresh(), $owner, $typeId);
            $this->assertSame([ApprovalStepType::HrPool->value], $this->stepTypes($request));

            $this->expectException(ValidationException::class);
            app(LeaveApprovalService::class)->approve($request->fresh(), $owner);
        });
    }

    public function test_manager_then_hr_is_a_two_step_flow_converting_usage_once(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($tenant, $owner) {
            [$manager, $mgrUser] = $this->worker('Man');
            [$employee, $empUser] = $this->worker('Emp');
            $employee->fill(['direct_manager_employee_id' => $manager->getKey()])->save();
            $typeId = $this->seedLeave($tenant, 'manager_then_hr', $employee->fresh());

            $request = $this->submit($employee->fresh(), $empUser, $typeId);
            $this->assertSame(
                [ApprovalStepType::DirectManager->value, ApprovalStepType::HrPool->value],
                $this->stepTypes($request),
            );

            // Step 1: manager approves → still pending, reservation retained.
            app(LeaveApprovalService::class)->approve($request->fresh(), $mgrUser);
            $this->assertSame('pending', $request->fresh()->status->value);
            $b = LeaveBalance::query()->first();
            $this->assertSame(480, $b->reserved_minutes);
            $this->assertSame(0, $b->used_minutes);

            // Step 2: HR pool (owner) approves → finalized, reservation→usage once.
            app(LeaveApprovalService::class)->approve($request->fresh(), $owner);
            $b = LeaveBalance::query()->first();
            $this->assertSame('approved', $request->fresh()->status->value);
            $this->assertSame(0, $b->reserved_minutes);
            $this->assertSame(480, $b->used_minutes);
        });
    }

    public function test_duplicate_final_approval_cannot_double_convert(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($tenant, $owner) {
            [$employee, $empUser] = $this->worker('Emp');
            $typeId = $this->seedLeave($tenant, 'hr', $employee->fresh());

            $request = $this->submit($employee->fresh(), $empUser, $typeId);
            app(LeaveApprovalService::class)->approve($request->fresh(), $owner);
            $this->assertSame(480, LeaveBalance::query()->first()->used_minutes);

            // A second approval of an already-approved request is refused.
            try {
                app(LeaveApprovalService::class)->approve($request->fresh(), $owner);
                $this->fail('expected the duplicate approval to be rejected');
            } catch (ValidationException) {
                // expected
            }
            $this->assertSame(480, LeaveBalance::query()->first()->used_minutes);
        });
    }
}
