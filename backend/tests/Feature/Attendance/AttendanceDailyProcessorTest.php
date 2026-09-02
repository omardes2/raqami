<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceDailyProcessor;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The cross-tenant daily processor materializes each tenant inside its own
 * context (RLS holds) and isolates failures. Each tenant only gets its own rows.
 */
class AttendanceDailyProcessorTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function scheduledEmployee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active',
            ]);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $working = $w >= 1 && $w <= 5;
                $days[] = [
                    'weekday' => $w, 'is_working_day' => $working,
                    'start_time' => $working ? '08:00' : null,
                    'end_time' => $working ? '16:00' : null,
                ];
            }
            $schedule = app(WorkScheduleService::class)->create(
                ['name' => 'Std', 'code' => 'STD', 'timezone' => 'UTC'], $days,
            );
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $employee;
        });
    }

    public function test_processor_materializes_each_tenant_independently(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner();
        [, $tenantB] = $this->createCompanyWithOwner();
        $empA = $this->scheduledEmployee($tenantA);
        $empB = $this->scheduledEmployee($tenantB);

        $totals = app(AttendanceDailyProcessor::class)->process(
            CarbonImmutable::parse('2026-03-02', 'UTC'),
            CarbonImmutable::parse('2026-03-02 11:00:00', 'UTC'),
        );

        $this->assertGreaterThanOrEqual(2, $totals['tenants']);
        $this->assertSame(0, $totals['errors']);
        $this->assertGreaterThanOrEqual(2, $totals['absent']);

        $context = app(TenantContext::class);
        $context->runAs($tenantA, function () use ($empA) {
            $this->assertSame(1, AttendanceRecord::count());
            $this->assertSame(AttendanceStatus::Absent, AttendanceRecord::first()->status);
            $this->assertSame((string) $empA->getKey(), (string) AttendanceRecord::first()->employee_id);
        });
        $context->runAs($tenantB, function () use ($empB) {
            $this->assertSame(1, AttendanceRecord::count());
            $this->assertSame((string) $empB->getKey(), (string) AttendanceRecord::first()->employee_id);
        });
    }

    public function test_command_runs_and_reports_success(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $this->scheduledEmployee($tenant);

        $this->artisan('attendance:process-daily', ['--date' => '2026-03-07'])
            ->assertExitCode(0);
    }
}
