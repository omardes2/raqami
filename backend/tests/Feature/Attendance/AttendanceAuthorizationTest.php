<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Authorization\Services\RoleAssignmentService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\TeamMembership;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Organizational-scope enforcement and GPS-sensitivity gating for attendance.
 * Managers only ever see their slice of the tenant; precise coordinates require
 * attendance.view_location within a scope that covers THAT employee (NB-1).
 */
class AttendanceAuthorizationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function companySchedule(): void
    {
        $days = [];
        for ($w = 0; $w <= 6; $w++) {
            $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '08:00', 'end_time' => '16:00'];
        }
        $s = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
        app(WorkScheduleService::class)->assign($s, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);
    }

    private function checkIn(Employee $employee): AttendanceRecord
    {
        return app(CheckInService::class)->checkIn(
            $employee,
            new PunchInput(latitude: 24.7136, longitude: 46.6753, accuracyMeters: 5),
            null,
            CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'),
        );
    }

    /** Grant an additional role to an existing user at a scope. */
    private function grant(Tenant $tenant, User $user, string $slug, string $scopeType, ?string $scopeId): void
    {
        $this->withinTenant($tenant, function () use ($user, $slug, $scopeType, $scopeId) {
            if (! TenantMembership::query()->where('user_id', $user->id)->exists()) {
                TenantMembership::create(['user_id' => $user->id, 'status' => 'active']);
            }
            app(RoleAssignmentService::class)->assignBySlug($user, $slug, $scopeType, $scopeId);
        });
    }

    public function test_branch_manager_sees_only_own_branch(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $branchA = $this->makeBranch($tenant, ['name' => 'A']);
        $branchB = $this->makeBranch($tenant, ['name' => 'B']);

        [$empA, $empB, $recordB] = $this->withinTenant($tenant, function () use ($branchA, $branchB) {
            $this->companySchedule();
            $a = app(EmployeeService::class)->create(['first_name' => 'A', 'last_name' => 'A', 'employment_status' => 'active', 'branch_id' => $branchA->id]);
            $b = app(EmployeeService::class)->create(['first_name' => 'B', 'last_name' => 'B', 'employment_status' => 'active', 'branch_id' => $branchB->id]);
            $this->checkIn($a->fresh());
            $rb = $this->checkIn($b->fresh());

            return [$a, $b, $rb];
        });

        $manager = $this->memberWithRole($tenant, 'department-manager', 'branch', $branchA->id);

        $list = $this->actingAs($manager)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/records')->assertOk();
        $ids = collect($list->json('data'))->pluck('employee_id')->all();
        $this->assertContains($empA->id, $ids);
        $this->assertNotContains($empB->id, $ids);

        // Direct lookup of an out-of-scope record is a scope-safe 404.
        $this->actingAs($manager)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/attendance/records/{$recordB->id}")->assertNotFound();
    }

    public function test_team_leader_sees_only_team_members(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $team = $this->makeTeam($tenant, ['name' => 'T']);

        [$member, $outsider] = $this->withinTenant($tenant, function () use ($team) {
            $this->companySchedule();
            $m = app(EmployeeService::class)->create(['first_name' => 'M', 'last_name' => 'M', 'employment_status' => 'active']);
            $o = app(EmployeeService::class)->create(['first_name' => 'O', 'last_name' => 'O', 'employment_status' => 'active']);
            TeamMembership::create(['team_id' => $team->id, 'employee_id' => $m->id, 'role_in_team' => 'member']);
            $this->checkIn($m->fresh());
            $this->checkIn($o->fresh());

            return [$m, $o];
        });

        $leader = $this->memberWithRole($tenant, 'team-leader', 'team', $team->id);

        $ids = collect($this->actingAs($leader)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/records')->assertOk()->json('data'))->pluck('employee_id')->all();

        $this->assertContains($member->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    public function test_department_manager_sees_own_department_subtree_only(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $branch = $this->makeBranch($tenant, ['name' => 'HQ']);
        // Parent department, a child department (subtree), and an unrelated one.
        $parentDept = $this->makeDepartment($tenant, ['name' => 'Eng', 'branch_id' => $branch->id]);
        $childDept = $this->makeDepartment($tenant, ['name' => 'Backend', 'branch_id' => $branch->id, 'parent_department_id' => $parentDept->id]);
        $otherDept = $this->makeDepartment($tenant, ['name' => 'Sales', 'branch_id' => $branch->id]);

        [$inParent, $inChild, $outside, $outsideRecord] = $this->withinTenant($tenant, function () use ($branch, $parentDept, $childDept, $otherDept) {
            $this->companySchedule();
            $p = app(EmployeeService::class)->create(['first_name' => 'P', 'last_name' => 'P', 'employment_status' => 'active', 'branch_id' => $branch->id, 'department_id' => $parentDept->id]);
            $c = app(EmployeeService::class)->create(['first_name' => 'C', 'last_name' => 'C', 'employment_status' => 'active', 'branch_id' => $branch->id, 'department_id' => $childDept->id]);
            $o = app(EmployeeService::class)->create(['first_name' => 'O', 'last_name' => 'O', 'employment_status' => 'active', 'branch_id' => $branch->id, 'department_id' => $otherDept->id]);
            $this->checkIn($p->fresh());
            $this->checkIn($c->fresh());
            $or = $this->checkIn($o->fresh());

            return [$p, $c, $o, $or];
        });

        // Manager scoped to the PARENT department only.
        $manager = $this->memberWithRole($tenant, 'department-manager', 'department', $parentDept->id);

        $ids = collect($this->actingAs($manager)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/records')->assertOk()->json('data'))->pluck('employee_id')->all();

        // Sees the department AND its descendant subtree, never the unrelated one.
        $this->assertContains($inParent->id, $ids);
        $this->assertContains($inChild->id, $ids);
        $this->assertNotContains($outside->id, $ids);

        // Direct access to an out-of-scope record is a scope-safe 404 (no leak).
        $this->actingAs($manager)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/attendance/records/{$outsideRecord->id}")->assertNotFound();
    }

    public function test_plain_employee_cannot_list_records(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $user = $this->memberWithRole($tenant, 'employee');

        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/records')->assertForbidden();
    }

    public function test_viewer_without_view_location_gets_no_coordinates(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $employee = $this->withinTenant($tenant, function () {
            $this->companySchedule();
            $e = app(EmployeeService::class)->create(['first_name' => 'G', 'last_name' => 'G', 'employment_status' => 'active']);
            $this->checkIn($e->fresh());

            return $e;
        });

        // Team leader (company scope) has attendance.view but NOT view_location.
        $viewer = $this->memberWithRole($tenant, 'team-leader', 'company');

        $row = collect($this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/records')->assertOk()->json('data'))
            ->firstWhere('employee_id', $employee->id);

        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('location', $row);
    }

    public function test_authorized_viewer_gets_coordinates(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $employee = $this->withinTenant($tenant, function () {
            $this->companySchedule();
            $e = app(EmployeeService::class)->create(['first_name' => 'G', 'last_name' => 'G', 'employment_status' => 'active']);
            $this->checkIn($e->fresh());

            return $e;
        });

        // Owner holds attendance.view_location company-wide.
        $row = collect($this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/records')->assertOk()->json('data'))
            ->firstWhere('employee_id', $employee->id);

        $this->assertArrayHasKey('location', $row);
        $this->assertNotNull($row['location']['check_in_latitude']);
    }

    public function test_view_location_at_different_scope_does_not_leak_gps(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $branchA = $this->makeBranch($tenant, ['name' => 'A']);
        $branchB = $this->makeBranch($tenant, ['name' => 'B']);

        [$empA, $empB] = $this->withinTenant($tenant, function () use ($branchA, $branchB) {
            $this->companySchedule();
            $a = app(EmployeeService::class)->create(['first_name' => 'A', 'last_name' => 'A', 'employment_status' => 'active', 'branch_id' => $branchA->id]);
            $b = app(EmployeeService::class)->create(['first_name' => 'B', 'last_name' => 'B', 'employment_status' => 'active', 'branch_id' => $branchB->id]);
            $this->checkIn($a->fresh());
            $this->checkIn($b->fresh());

            return [$a, $b];
        });

        // User can SEE every row (company-wide view via team-leader) but holds
        // view_location only for branch A (via hr-manager scoped to branch A).
        $user = $this->memberWithRole($tenant, 'team-leader', 'company');
        $this->grant($tenant, $user, 'hr-manager', 'branch', $branchA->id);

        $rows = collect($this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/records')->assertOk()->json('data'));

        $rowA = $rows->firstWhere('employee_id', $empA->id);
        $rowB = $rows->firstWhere('employee_id', $empB->id);

        // Both rows visible; GPS only for the in-scope (branch A) employee.
        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $this->assertArrayHasKey('location', $rowA);
        $this->assertArrayNotHasKey('location', $rowB);
    }
}
