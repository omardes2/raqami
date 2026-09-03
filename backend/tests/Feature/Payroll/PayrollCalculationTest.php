<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * End-to-end calculation: schedule → compensation → run → calculate job → entry.
 * Sept 2026 has 30 days; a 7-day 08:00–16:00 (480m) schedule ⇒ 14400 expected
 * minutes, so a full-month base equals the exact monthly amount.
 */
class PayrollCalculationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private const SEPT_EXPECTED = 14400; // 30 * 480

    /** A full-time employee scheduled every day 08:00–16:00 UTC. */
    private function fullTimeEmployee(Tenant $tenant, array $attrs = []): Employee
    {
        return $this->withinTenant($tenant, function () use ($attrs) {
            $employee = app(EmployeeService::class)->create(array_merge(
                ['first_name' => 'Full', 'last_name' => 'Time', 'employment_status' => 'active', 'hire_date' => '2020-01-01'],
                $attrs,
            ));

            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S'.$employee->getKey(), 'code' => 'S'.$employee->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'employee', 'scope_id' => (string) $employee->getKey(), 'effective_from' => '2020-01-01']);

            return $employee->fresh();
        });
    }

    private function septemberRun(Tenant $tenant, $owner): PayrollRun
    {
        return $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']);

            return app(PayrollRunService::class)->create($owner, $period);
        });
    }

    /**
     * Request calculation (job faked so it is not run inline — its tenant-context
     * middleware would otherwise clear the caller's context), then run the executor
     * directly, exactly as the worker would. Must be called inside a tenant context.
     */
    private function runCalc($owner, PayrollRun $run, string $method = 'calculate'): void
    {
        Queue::fake();
        app(PayrollCalculationService::class)->{$method}($owner, $run->fresh());
        app(PayrollCalculationService::class)->execute((string) $run->getKey());
    }

    public function test_full_month_single_employee_calculates_exact_base(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->fullTimeEmployee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $emp) {
            app(EmployeeCompensationService::class)->create($owner, (string) $emp->getKey(), [
                'currency' => 'USD', 'base_amount_minor' => 300000, 'effective_from' => '2020-01-01',
            ]);
        });

        $run = $this->septemberRun($tenant, $owner);

        $this->withinTenant($tenant, function () use ($owner, $run, $emp) {
            $this->runCalc($owner, $run);

            $this->assertSame(PayrollRunStatus::Calculated, $run->fresh()->status);

            $entry = PayrollEntry::query()->where('payroll_run_id', $run->getKey())->where('employee_id', $emp->getKey())->firstOrFail();
            $this->assertSame(PayrollEntryStatus::Calculated, $entry->status);
            $this->assertSame('USD', $entry->currency);
            $this->assertSame(300000, $entry->gross_minor);
            $this->assertSame(0, $entry->deduction_minor);
            $this->assertSame(300000, $entry->net_minor);
            $this->assertNotNull($entry->input_fingerprint);
            $this->assertNotNull($entry->input_snapshot);
            $this->assertSame(self::SEPT_EXPECTED, $entry->input_snapshot['schedule']['period_expected_minutes']);
        });
    }

    public function test_hire_mid_month_prorates_but_denominator_stays_full_month(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        // Hired 2026-09-16 ⇒ payable Sep 16–30 = 15 days * 480 = 7200 of 14400.
        $emp = $this->fullTimeEmployee($tenant, ['hire_date' => '2026-09-16']);

        $this->withinTenant($tenant, function () use ($owner, $emp) {
            app(EmployeeCompensationService::class)->create($owner, (string) $emp->getKey(), [
                'currency' => 'USD', 'base_amount_minor' => 300000, 'effective_from' => '2020-01-01',
            ]);
        });
        $run = $this->septemberRun($tenant, $owner);

        $this->withinTenant($tenant, function () use ($owner, $run, $emp) {
            $this->runCalc($owner, $run);
            $entry = PayrollEntry::query()->where('employee_id', $emp->getKey())->firstOrFail();
            $this->assertSame(PayrollEntryStatus::Calculated, $entry->status);
            // 300000 * 7200 / 14400 = 150000
            $this->assertSame(150000, $entry->gross_minor);
        });
    }

    public function test_missing_compensation_fails_entry_but_run_completes(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->fullTimeEmployee($tenant); // no compensation created
        $run = $this->septemberRun($tenant, $owner);

        $this->withinTenant($tenant, function () use ($owner, $run, $emp) {
            $this->runCalc($owner, $run);

            $this->assertSame(PayrollRunStatus::CalculationFailed, $run->fresh()->status);
            $entry = PayrollEntry::query()->where('employee_id', $emp->getKey())->firstOrFail();
            $this->assertSame(PayrollEntryStatus::Failed, $entry->status);
            $this->assertSame('missing_compensation', $entry->error_code);
            $this->assertNull($entry->gross_minor);
        });
    }

    public function test_recalculation_replaces_lines_without_duplication(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->fullTimeEmployee($tenant);
        $this->withinTenant($tenant, fn () => app(EmployeeCompensationService::class)->create($owner, (string) $emp->getKey(), [
            'currency' => 'USD', 'base_amount_minor' => 300000, 'effective_from' => '2020-01-01',
        ]));
        $run = $this->septemberRun($tenant, $owner);

        $this->withinTenant($tenant, function () use ($owner, $run, $emp) {
            $this->runCalc($owner, $run);
            $entry = PayrollEntry::query()->where('employee_id', $emp->getKey())->firstOrFail();
            $this->assertSame(1, DB::table('payroll_entry_lines')->where('payroll_entry_id', $entry->getKey())->count());

            $this->runCalc($owner, $run, 'recalculate');
            $this->assertSame(1, DB::table('payroll_entry_lines')->where('payroll_entry_id', $entry->getKey())->count());
            $this->assertSame(300000, $entry->fresh()->gross_minor);
        });
    }
}
