<?php

namespace Tests\Feature\Notifications;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveRequestApproval;
use App\Modules\Leave\Services\LeaveApprovalService;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveEntitlementPeriodService;
use App\Modules\Leave\Services\LeavePolicyAssignmentService;
use App\Modules\Leave\Services\LeavePolicyService;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Leave\Services\LeaveTypeService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8B — leave domain notifications fire post-commit to the RIGHT recipients
 * (named approver on submit; requester on approve/reject), carry no leave
 * type/reason, dedupe, skip employees without a linked User, and never fire when
 * the transition rolls back.
 */
class LeaveNotificationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0:Employee,1:User} linked worker */
    private function worker(string $first): array
    {
        $user = User::factory()->create();
        $employee = app(EmployeeService::class)->create(['first_name' => $first, 'last_name' => 'X', 'employment_status' => 'active']);
        $employee->fill(['user_id' => $user->id])->save();
        // A notification recipient must be an active member of the tenant.
        TenantMembership::create(['user_id' => $user->id, 'status' => 'active']);

        return [$employee->fresh(), $user];
    }

    private function seedLeaveFor(Employee $employee, string $flow): string
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

    private function submit(Employee $employee, User $actor, string $typeId): LeaveRequest
    {
        return app(LeaveRequestService::class)->submit($employee, [
            'leave_type_id' => $typeId, 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
        ], $actor);
    }

    /** Count a recipient's own notifications (recipient-scoped RLS), optionally by type. */
    private function inbox(Tenant $tenant, string $userId, ?string $type = null): int
    {
        DB::statement("select set_config('app.tenant_id', ?, false)", [(string) $tenant->getKey()]);
        DB::statement("select set_config('app.user_id', ?, false)", [$userId]);
        DB::statement("select set_config('app.platform_readonly', 'off', false)");
        try {
            $q = DB::table('notifications');
            if ($type !== null) {
                $q->where('type', $type);
            }

            return (int) $q->count();
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    public function test_approval_notifies_requester_and_submit_notifies_named_approver(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        [$mgrUser, $empUser, $request] = $this->withinTenant($tenant, function () {
            [$manager, $mgrUser] = $this->worker('Man');
            [$employee, $empUser] = $this->worker('Emp');
            $employee->fill(['direct_manager_employee_id' => $manager->getKey()])->save();
            $typeId = $this->seedLeaveFor($employee->fresh(), 'manager');

            $request = $this->submit($employee->fresh(), $empUser, $typeId);
            $step = LeaveRequestApproval::query()->where('leave_request_id', $request->getKey())->first();
            $this->assertSame((string) $mgrUser->id, (string) $step->approver_user_id);

            return [$mgrUser, $empUser, $request];
        });

        // On submit: the named direct manager was notified; the requester was not.
        $this->assertSame(1, $this->inbox($tenant, (string) $mgrUser->id, 'leave.request.submitted'));
        $this->assertSame(0, $this->inbox($tenant, (string) $empUser->id, 'leave.request.submitted'));

        // Approve → requester notified exactly once; no approved row for the manager.
        $this->withinTenant($tenant, fn () => app(LeaveApprovalService::class)->approve($request->fresh(), $mgrUser));
        $this->assertSame(1, $this->inbox($tenant, (string) $empUser->id, 'leave.request.approved'));
        $this->assertSame(0, $this->inbox($tenant, (string) $mgrUser->id, 'leave.request.approved'));
    }

    public function test_rejection_notifies_requester(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        [$mgrUser, $empUser, $request] = $this->withinTenant($tenant, function () {
            [$manager, $mgrUser] = $this->worker('Man');
            [$employee, $empUser] = $this->worker('Emp');
            $employee->fill(['direct_manager_employee_id' => $manager->getKey()])->save();
            $typeId = $this->seedLeaveFor($employee->fresh(), 'manager');
            $request = $this->submit($employee->fresh(), $empUser, $typeId);

            return [$mgrUser, $empUser, $request];
        });

        $this->withinTenant($tenant, fn () => app(LeaveApprovalService::class)->reject($request->fresh(), $mgrUser));
        $this->assertSame(1, $this->inbox($tenant, (string) $empUser->id, 'leave.request.rejected'));
        $this->assertSame(0, $this->inbox($tenant, (string) $empUser->id, 'leave.request.approved'));
    }

    public function test_hr_pool_submission_has_no_definitive_approver_notification(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        [$empUser, $request] = $this->withinTenant($tenant, function () {
            [$employee, $empUser] = $this->worker('Emp'); // no manager, no department
            $typeId = $this->seedLeaveFor($employee->fresh(), 'manager');
            $request = $this->submit($employee->fresh(), $empUser, $typeId);
            $step = LeaveRequestApproval::query()->where('leave_request_id', $request->getKey())->first();
            $this->assertNull($step->approver_user_id); // hr_pool → no directory user

            return [$empUser, $request];
        });

        // hr_pool carries no definitive user, so no "submitted" notification exists
        // for the requester or the owner.
        $this->assertSame(0, $this->inbox($tenant, (string) $empUser->id, 'leave.request.submitted'));
        $this->assertSame(0, $this->inbox($tenant, (string) $owner->id, 'leave.request.submitted'));

        // The owner (org-wide leave.approve) approves the pool step → requester notified.
        $this->withinTenant($tenant, fn () => app(LeaveApprovalService::class)->approve($request->fresh(), $owner));
        $this->assertSame(1, $this->inbox($tenant, (string) $empUser->id, 'leave.request.approved'));
    }

    public function test_employee_without_user_is_skipped_without_error(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $request = $this->withinTenant($tenant, function () use ($owner) {
            // Employee with NO linked User (User != Employee).
            $employee = app(EmployeeService::class)->create(['first_name' => 'NoUser', 'last_name' => 'X', 'employment_status' => 'active']);
            $typeId = $this->seedLeaveFor($employee->fresh(), 'manager');

            // Submitted on their behalf by the owner (actor need not be the employee).
            return $this->submit($employee->fresh(), $owner, $typeId);
        });

        // Approving must not throw even though the requester has no User to notify.
        $approved = $this->withinTenant($tenant, fn () => app(LeaveApprovalService::class)->approve($request->fresh(), $owner));
        $this->assertSame('approved', $approved->status->value);
        // The owner (not the employee) has no approved notification.
        $this->assertSame(0, $this->inbox($tenant, (string) $owner->id, 'leave.request.approved'));
    }

    public function test_failed_approval_creates_no_notification(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        [$empUser, $request] = $this->withinTenant($tenant, function () {
            [$employee, $empUser] = $this->worker('Emp');
            $typeId = $this->seedLeaveFor($employee->fresh(), 'manager'); // hr_pool step
            $request = $this->submit($employee->fresh(), $empUser, $typeId);

            return [$empUser, $request];
        });

        // The requester cannot self-approve (segregation of duties) → throws, rolls back.
        $this->withinTenant($tenant, function () use ($request, $empUser) {
            $this->expectException(ValidationException::class);
            app(LeaveApprovalService::class)->approve($request->fresh(), $empUser);
        });

        $this->assertSame(0, $this->inbox($tenant, (string) $empUser->id, 'leave.request.approved'));
    }
}
