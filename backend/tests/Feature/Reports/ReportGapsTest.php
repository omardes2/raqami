<?php

namespace Tests\Feature\Reports;

use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Attendance\Services\AttendanceReportService;
use App\Modules\Attendance\Services\ManualAttendanceService;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeavePolicy;
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

    public function test_attendance_by_unit_counts_records_not_sessions(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $branch = $this->makeBranch($tenant);
        $emp = $this->employeeInBranch($tenant, $branch);

        $this->withinTenant($tenant, function () use ($emp, $owner, $tenant) {
            $record = app(ManualAttendanceService::class)->record($emp, ['check_in_at' => '2026-03-02 08:00:00', 'check_out_at' => '2026-03-02 16:00:00', 'reason' => 'x'], $owner);
            // Inject two EXTRA sessions for the same logical day. If by-unit summed
            // sessions instead of records, records would be 3, not 1.
            foreach ([2, 3] as $seq) {
                AttendanceSession::query()->create([
                    'tenant_id' => $tenant->id, 'attendance_record_id' => $record->getKey(), 'employee_id' => $emp->id,
                    'sequence' => $seq, 'check_in_at' => '2026-03-02 08:00:00', 'check_out_at' => '2026-03-02 12:00:00',
                    'worked_minutes' => 240,
                ]);
            }
        });

        $admin = $this->memberWithRole($tenant, 'admin');
        $units = $this->withinTenant($tenant, fn () => app(AttendanceReportService::class)
            ->byUnit($admin, ['from' => '2026-03-01', 'to' => '2026-03-31'], 'branch'));

        $this->assertCount(1, $units);
        // Exactly one logical-day record despite three sessions.
        $this->assertSame(1, $units[0]['records']);
        $this->withinTenant($tenant, fn () => $this->assertSame(3, AttendanceSession::query()->count()));
    }

    public function test_attendance_by_unit_is_tenant_isolated(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        $branchA = $this->makeBranch($tenantA);
        $empA = $this->employeeInBranch($tenantA, $branchA);
        $this->withinTenant($tenantA, fn () => app(ManualAttendanceService::class)->record($empA, ['check_in_at' => '2026-03-02 08:00:00', 'check_out_at' => '2026-03-02 16:00:00', 'reason' => 'x'], $ownerA));

        [$ownerB, $tenantB] = $this->createCompanyWithOwner();
        $branchB = $this->makeBranch($tenantB);
        $empB = $this->employeeInBranch($tenantB, $branchB);
        $this->withinTenant($tenantB, fn () => app(ManualAttendanceService::class)->record($empB, ['check_in_at' => '2026-03-02 08:00:00', 'check_out_at' => '2026-03-02 16:00:00', 'reason' => 'x'], $ownerB));

        $adminA = $this->memberWithRole($tenantA, 'admin');
        $units = $this->withinTenant($tenantA, fn () => app(AttendanceReportService::class)
            ->byUnit($adminA, ['from' => '2026-03-01', 'to' => '2026-03-31'], 'branch'));

        $this->assertCount(1, $units);
        $this->assertSame((string) $branchA->id, $units[0]['unit_id']);
        $this->assertNotSame((string) $branchB->id, $units[0]['unit_id']);
    }

    /** Shared leave infra (schedule + type + company policy). Returns the leave type id. */
    private function seedLeaveInfra(Tenant $tenant): string
    {
        return $this->withinTenant($tenant, function () {
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

            return (string) $type->getKey();
        });
    }

    private function leaveEmployee(Tenant $tenant, ?Branch $branch = null): Employee
    {
        return $this->withinTenant($tenant, function () use ($branch) {
            $user = $this->makeUser();
            $e = app(EmployeeService::class)->create(array_filter([
                'first_name' => 'L', 'last_name' => 'V', 'employment_status' => 'active', 'branch_id' => $branch?->id,
            ]));
            $e->fill(['user_id' => $user->id])->save();

            return $e->fresh();
        });
    }

    private function submitLeave(Tenant $tenant, Employee $employee, string $typeId): void
    {
        $this->withinTenant($tenant, function () use ($employee, $typeId) {
            $policy = LeavePolicy::query()->where('leave_type_id', $typeId)->firstOrFail();
            $period = app(LeaveEntitlementPeriodService::class)->resolveOrCreate($employee->fresh(), $typeId, $policy, CarbonImmutable::parse('2026-06-15'));
            $svc = app(LeaveBalanceService::class);
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 100000)));
            app(LeaveRequestService::class)->submit($employee->fresh(), [
                'leave_type_id' => $typeId, 'starts_on' => '2026-06-15', 'ends_on' => '2026-06-15',
            ], User::find($employee->user_id));
        });
    }

    public function test_leave_requests_by_status_counts_and_hides_private_fields(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $typeId = $this->seedLeaveInfra($tenant);
        $this->submitLeave($tenant, $this->leaveEmployee($tenant), $typeId);

        $result = $this->withinTenant($tenant, fn () => app(LeaveReportService::class)->requestsByStatus(
            $this->rescope($tenant, $owner),
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-12-31'),
        ));

        $this->assertSame(1, $result['total_requests']);
        $this->assertNotEmpty($result['by_status']);
        // Truthful field name; no private keys.
        $flat = json_encode($result);
        $this->assertStringContainsString('requested_consumption_minutes', $flat);
        foreach (['reason', 'attachment', 'medical', 'note'] as $needle) {
            $this->assertStringNotContainsString($needle, $flat);
        }
        $this->withinTenant($tenant, fn () => $this->assertSame(1, LeaveRequest::query()->count()));
    }

    public function test_leave_requests_by_status_confined_to_scope(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $branchA = $this->makeBranch($tenant);
        $branchB = $this->makeBranch($tenant);
        $typeId = $this->seedLeaveInfra($tenant);
        $this->submitLeave($tenant, $this->leaveEmployee($tenant, $branchA), $typeId);
        $this->submitLeave($tenant, $this->leaveEmployee($tenant, $branchB), $typeId);

        // Viewer scoped to branch A only (leave.reports.view via department-manager).
        $scopedViewer = $this->memberWithRole($tenant, 'department-manager', 'branch', (string) $branchA->id);
        $result = $this->withinTenant($tenant, fn () => app(LeaveReportService::class)->requestsByStatus(
            $this->rescope($tenant, $scopedViewer),
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-12-31'),
        ));

        // Only branch A's single request; branch B contributes zero.
        $this->assertSame(1, $result['total_requests']);
        $this->withinTenant($tenant, fn () => $this->assertSame(2, LeaveRequest::query()->count()));
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
