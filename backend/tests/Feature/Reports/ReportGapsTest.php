<?php

namespace Tests\Feature\Reports;

use App\Modules\Attendance\Services\AttendanceReportService;
use App\Modules\Attendance\Services\ManualAttendanceService;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveEntitlementPeriodService;
use App\Modules\Leave\Services\LeavePolicyAssignmentService;
use App\Modules\Leave\Services\LeavePolicyService;
use App\Modules\Leave\Services\LeaveReportService;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Leave\Services\LeaveTypeService;
use App\Modules\Organization\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8A module report gaps: attendance organization (by-unit) grouping and the
 * leave requests-by-status summary. Both are scope-constrained and privacy-safe.
 */
class ReportGapsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employeeInBranch(Tenant $tenant, Branch $branch): Employee
    {
        return $this->withinTenant($tenant, function () use ($branch) {
            $e = app(EmployeeService::class)->create([
                'first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active', 'branch_id' => $branch->id,
            ]);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $s = app(WorkScheduleService::class)->create(['name' => 'S'.$e->getKey(), 'code' => 'S'.$e->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($s, ['scope_type' => 'employee', 'scope_id' => (string) $e->getKey(), 'effective_from' => '2026-01-01']);

            return $e->fresh();
        });
    }

    public function test_attendance_by_unit_groups_by_branch_within_scope(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $branchA = $this->makeBranch($tenant);
        $branchB = $this->makeBranch($tenant);
        $empA = $this->employeeInBranch($tenant, $branchA);
        $empB = $this->employeeInBranch($tenant, $branchB);

        $this->withinTenant($tenant, function () use ($empA, $empB, $owner) {
            app(ManualAttendanceService::class)->record($empA, ['check_in_at' => '2026-03-02 08:00:00', 'check_out_at' => '2026-03-02 16:00:00', 'reason' => 'x'], $owner);
            app(ManualAttendanceService::class)->record($empB, ['check_in_at' => '2026-03-02 08:00:00', 'check_out_at' => '2026-03-02 16:00:00', 'reason' => 'x'], $owner);
        });

        // Company viewer: two units, one record each. (Service calls run inside the
        // tenant context that the HTTP tenant middleware would normally establish.)
        $admin = $this->memberWithRole($tenant, 'admin');
        $units = $this->withinTenant($tenant, fn () => app(AttendanceReportService::class)
            ->byUnit($admin, ['from' => '2026-03-01', 'to' => '2026-03-31'], 'branch'));
        $this->assertCount(2, $units);
        $byBranch = collect($units)->keyBy('unit_id');
        $this->assertSame(1, $byBranch[(string) $branchA->id]['records']);
        $this->assertSame(1, $byBranch[(string) $branchB->id]['records']);

        // Branch-A-scoped viewer sees only branch A's unit.
        $scoped = $this->memberWithRole($tenant, 'department-manager', 'branch', (string) $branchA->id);
        $scopedUnits = $this->withinTenant($tenant, fn () => app(AttendanceReportService::class)
            ->byUnit($scoped, ['from' => '2026-03-01', 'to' => '2026-03-31'], 'branch'));
        $this->assertCount(1, $scopedUnits);
        $this->assertSame((string) $branchA->id, $scopedUnits[0]['unit_id']);
    }

    private function seedApprovedLeave(Tenant $tenant): void
    {
        $this->withinTenant($tenant, function () {
            $user = $this->makeUser();
            $employee = app(EmployeeService::class)->create(['first_name' => 'L', 'last_name' => 'V', 'employment_status' => 'active']);
            $employee->fill(['user_id' => $user->id])->save();

            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'LS', 'code' => 'LS', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual']);
            $policy = app(LeavePolicyService::class)->create([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'entitlement_method' => 'none', 'approval_flow' => 'none',
            ]);
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);
            $period = app(LeaveEntitlementPeriodService::class)->resolveOrCreate($employee->fresh(), $type->getKey(), $policy, CarbonImmutable::parse('2026-06-15'));
            $svc = app(LeaveBalanceService::class);
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 100000)));

            app(LeaveRequestService::class)->submit($employee->fresh(), [
                'leave_type_id' => $type->getKey(), 'starts_on' => '2026-06-15', 'ends_on' => '2026-06-15',
            ], $user);
        });
    }

    public function test_leave_requests_by_status_counts_and_hides_private_fields(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedApprovedLeave($tenant);

        $result = $this->withinTenant($tenant, fn () => app(LeaveReportService::class)->requestsByStatus(
            $this->rescope($tenant, $owner),
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-12-31'),
        ));

        $this->assertSame(1, $result['total_requests']);
        $this->assertNotEmpty($result['by_status']);
        // Privacy: the aggregate carries no reason/attachment/medical keys.
        $flat = json_encode($result);
        foreach (['reason', 'attachment', 'medical', 'note'] as $needle) {
            $this->assertStringNotContainsString($needle, $flat);
        }
        // Sanity: at least one row exists in the DB for the tenant.
        $this->withinTenant($tenant, fn () => $this->assertSame(1, LeaveRequest::query()->count()));
    }

    private function rescope(Tenant $tenant, $user): Builder
    {
        return $this->withinTenant($tenant, function () use ($user) {
            $q = Employee::query();
            app(EmployeeScopeResolver::class)->applyScope($q, $user, 'leave.reports.view');

            return $q;
        });
    }
}
