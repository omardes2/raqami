<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Services\ScheduleResolver;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Services\EmployeeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * ScheduleResolver is the single deterministic authority for which schedule
 * applies. These tests lock the precedence order and the overnight/timezone
 * resolution the whole attendance module depends on.
 */
class ScheduleResolutionTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function schedule(string $code): WorkSchedule
    {
        $days = [];
        for ($w = 0; $w <= 6; $w++) {
            $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '08:00', 'end_time' => '16:00'];
        }

        return app(WorkScheduleService::class)->create(
            ['name' => $code, 'code' => $code, 'timezone' => 'UTC'], $days,
        );
    }

    public function test_employee_scope_wins_over_company_scope(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active',
            ]);

            $company = $this->schedule('COMPANY');
            $personal = $this->schedule('PERSONAL');

            app(WorkScheduleService::class)->assign($company, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);
            app(WorkScheduleService::class)->assign($personal, ['scope_type' => 'employee', 'scope_id' => $employee->id, 'effective_from' => '2026-01-01']);

            $resolved = app(ScheduleResolver::class)->resolveSchedule($employee, CarbonImmutable::parse('2026-03-02'));

            $this->assertSame('PERSONAL', $resolved->code);
        });
    }

    public function test_department_scope_wins_over_branch_scope(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $branch = $this->makeBranch($tenant, ['name' => 'HQ']);
        $dept = $this->makeDepartment($tenant, ['name' => 'Eng', 'branch_id' => $branch->id]);

        $this->withinTenant($tenant, function () use ($branch, $dept) {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active',
                'branch_id' => $branch->id, 'department_id' => $dept->id,
            ]);

            $branchSchedule = $this->schedule('BRANCH');
            $deptSchedule = $this->schedule('DEPT');

            app(WorkScheduleService::class)->assign($branchSchedule, ['scope_type' => 'branch', 'scope_id' => $branch->id, 'effective_from' => '2026-01-01']);
            app(WorkScheduleService::class)->assign($deptSchedule, ['scope_type' => 'department', 'scope_id' => $dept->id, 'effective_from' => '2026-01-01']);

            $resolved = app(ScheduleResolver::class)->resolveSchedule($employee, CarbonImmutable::parse('2026-03-02'));

            $this->assertSame('DEPT', $resolved->code);
        });
    }

    public function test_no_assignment_resolves_to_null(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active',
            ]);

            $this->assertNull(app(ScheduleResolver::class)->resolveSchedule($employee, CarbonImmutable::parse('2026-03-02')));
        });
    }

    public function test_overnight_schedule_extends_end_to_next_day(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active',
            ]);

            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '22:00', 'end_time' => '06:00'];
            }
            $night = app(WorkScheduleService::class)->create(['name' => 'N', 'code' => 'N', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($night, ['scope_type' => 'employee', 'scope_id' => $employee->id, 'effective_from' => '2026-01-01']);

            // Check-in at 22:00 on 2026-03-02.
            $resolved = app(ScheduleResolver::class)->resolveWorkDay(
                $employee->fresh(),
                CarbonImmutable::parse('2026-03-02 22:00:00', 'UTC'),
                'UTC',
            );

            $this->assertTrue($resolved->isScheduledWorkingDay());
            $this->assertSame('2026-03-02', $resolved->workDate->toDateString());
            $this->assertSame('2026-03-02 22:00:00', $resolved->scheduledStartAt->format('Y-m-d H:i:s'));
            $this->assertSame('2026-03-03 06:00:00', $resolved->scheduledEndAt->format('Y-m-d H:i:s'));
        });
    }
}
