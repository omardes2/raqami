<?php

namespace Tests\Feature\Leave;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Services\LeavePolicyAssignmentService;
use App\Modules\Leave\Services\LeavePolicyResolver;
use App\Modules\Leave\Services\LeavePolicyService;
use App\Modules\Leave\Services\LeaveTypeService;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Team;
use App\Modules\Organization\Models\TeamMembership;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * LeavePolicyResolver precedence (mirrors ScheduleResolver): employee > team >
 * department (deepest ancestor first) > branch > company; ties broken by priority
 * desc, then effective_from desc; and effective-dated assignments only apply on
 * dates their window covers.
 */
class LeavePolicyResolutionTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private string $typeId;

    /** Create a distinct policy (identified by its name) for the shared type. */
    private function policy(string $name): LeavePolicy
    {
        return app(LeavePolicyService::class)->create([
            'leave_type_id' => $this->typeId, 'name' => $name, 'effective_from' => '2026-01-01',
            'entitlement_method' => 'none', 'approval_flow' => 'none',
        ]);
    }

    private function assign(LeavePolicy $policy, array $input): void
    {
        app(LeavePolicyAssignmentService::class)->assign($policy, $input);
    }

    private function resolve(Employee $employee): ?string
    {
        return app(LeavePolicyResolver::class)
            ->resolve($employee->fresh(), $this->typeId, CarbonImmutable::parse('2027-06-15'))?->name;
    }

    private function makeType(): void
    {
        $this->typeId = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual'])->getKey();
    }

    public function test_more_specific_scope_wins_employee_over_department_over_company(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $this->makeType();
            $department = Department::factory()->create();
            $employee = app(EmployeeService::class)->create(['first_name' => 'E', 'last_name' => 'E', 'employment_status' => 'active', 'department_id' => $department->getKey()]);

            $this->assign($this->policy('COMPANY'), ['scope_type' => 'company', 'effective_from' => '2026-01-01']);
            $this->assign($this->policy('DEPT'), ['scope_type' => 'department', 'scope_id' => $department->getKey(), 'effective_from' => '2026-01-01']);
            $this->assertSame('DEPT', $this->resolve($employee)); // dept beats company

            $this->assign($this->policy('EMP'), ['scope_type' => 'employee', 'scope_id' => $employee->getKey(), 'effective_from' => '2026-01-01']);
            $this->assertSame('EMP', $this->resolve($employee)); // employee beats all
        });
    }

    public function test_team_beats_department(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $this->makeType();
            $department = Department::factory()->create();
            $team = Team::factory()->create();
            $employee = app(EmployeeService::class)->create(['first_name' => 'E', 'last_name' => 'E', 'employment_status' => 'active', 'department_id' => $department->getKey()]);
            TeamMembership::create(['team_id' => $team->getKey(), 'employee_id' => $employee->getKey(), 'role_in_team' => 'member']);

            $this->assign($this->policy('DEPT'), ['scope_type' => 'department', 'scope_id' => $department->getKey(), 'effective_from' => '2026-01-01']);
            $this->assign($this->policy('TEAM'), ['scope_type' => 'team', 'scope_id' => $team->getKey(), 'effective_from' => '2026-01-01']);

            $this->assertSame('TEAM', $this->resolve($employee));
        });
    }

    public function test_deepest_department_ancestor_wins(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $this->makeType();
            $parent = Department::factory()->create();
            $child = Department::factory()->create(['parent_department_id' => $parent->getKey()]);
            $employee = app(EmployeeService::class)->create(['first_name' => 'E', 'last_name' => 'E', 'employment_status' => 'active', 'department_id' => $child->getKey()]);

            $this->assign($this->policy('PARENT'), ['scope_type' => 'department', 'scope_id' => $parent->getKey(), 'effective_from' => '2026-01-01']);
            $this->assign($this->policy('CHILD'), ['scope_type' => 'department', 'scope_id' => $child->getKey(), 'effective_from' => '2026-01-01']);

            $this->assertSame('CHILD', $this->resolve($employee)); // deepest (own) dept first
        });
    }

    public function test_branch_beats_company_but_loses_to_department(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $this->makeType();
            $branch = Branch::factory()->create();
            $department = Department::factory()->create();
            $employee = app(EmployeeService::class)->create(['first_name' => 'E', 'last_name' => 'E', 'employment_status' => 'active', 'branch_id' => $branch->getKey(), 'department_id' => $department->getKey()]);

            $this->assign($this->policy('COMPANY'), ['scope_type' => 'company', 'effective_from' => '2026-01-01']);
            $this->assign($this->policy('BRANCH'), ['scope_type' => 'branch', 'scope_id' => $branch->getKey(), 'effective_from' => '2026-01-01']);
            $this->assertSame('BRANCH', $this->resolve($employee)); // branch beats company

            $this->assign($this->policy('DEPT'), ['scope_type' => 'department', 'scope_id' => $department->getKey(), 'effective_from' => '2026-01-01']);
            $this->assertSame('DEPT', $this->resolve($employee)); // department beats branch
        });
    }

    public function test_same_level_tie_break_is_priority_desc(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $this->makeType();
            $employee = app(EmployeeService::class)->create(['first_name' => 'E', 'last_name' => 'E', 'employment_status' => 'active']);

            $this->assign($this->policy('LOW'), ['scope_type' => 'company', 'effective_from' => '2026-01-01', 'priority' => 1]);
            $this->assign($this->policy('HIGH'), ['scope_type' => 'company', 'effective_from' => '2026-01-01', 'priority' => 9]);

            $this->assertSame('HIGH', $this->resolve($employee)); // highest priority wins the tie
        });
    }

    public function test_assignment_outside_its_effective_window_is_ignored(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $this->makeType();
            $employee = app(EmployeeService::class)->create(['first_name' => 'E', 'last_name' => 'E', 'employment_status' => 'active']);

            // A future-only company assignment does not cover 2027-06-15.
            $future = app(LeavePolicyService::class)->create([
                'leave_type_id' => $this->typeId, 'name' => 'FUTURE', 'effective_from' => '2030-01-01',
                'entitlement_method' => 'none', 'approval_flow' => 'none',
            ]);
            $this->assign($future, ['scope_type' => 'company', 'effective_from' => '2030-01-01']);
            $this->assertNull($this->resolve($employee));

            // An active company assignment covering the date is chosen.
            $this->assign($this->policy('ACTIVE'), ['scope_type' => 'company', 'effective_from' => '2026-01-01']);
            $this->assertSame('ACTIVE', $this->resolve($employee));
        });
    }
}
