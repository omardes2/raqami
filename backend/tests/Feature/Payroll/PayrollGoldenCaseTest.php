<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollEntryLine;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationComponentService;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollComponentService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\PayrollSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Permanent financial golden regression: Sept 2026, UTC, Mon–Fri 08:00–16:00, no
 * holidays ⇒ 22 working days = 10,560 expected minutes. Exact minor-unit results.
 */
class PayrollGoldenCaseTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private const DENOM = 10560;

    /** Mon–Fri 08:00–16:00 employee (Sat/Sun non-working). */
    private function monFriEmployee(Tenant $tenant, array $attrs = []): Employee
    {
        return $this->withinTenant($tenant, function () use ($attrs) {
            $employee = app(EmployeeService::class)->create(array_merge(['first_name' => 'G', 'last_name' => 'C', 'employment_status' => 'active', 'hire_date' => '2020-01-01'], $attrs));
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $working = $w >= 1 && $w <= 5; // Carbon dayOfWeek: Sun=0..Sat=6
                $days[] = ['weekday' => $w, 'is_working_day' => $working, 'segments' => $working ? [['start_time' => '08:00', 'end_time' => '16:00']] : []];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S'.$employee->getKey(), 'code' => 'S'.$employee->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'employee', 'scope_id' => (string) $employee->getKey(), 'effective_from' => '2020-01-01']);

            return $employee->fresh();
        });
    }

    private function runFor(Tenant $tenant, $owner): PayrollRun
    {
        return $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']);
            $run = app(PayrollRunService::class)->create($owner, $period);
            Queue::fake();
            app(PayrollCalculationService::class)->calculate($owner, $run->fresh());
            app(PayrollCalculationService::class)->execute((string) $run->getKey());

            return $run->fresh();
        });
    }

    private function entry(Tenant $tenant, PayrollRun $run, Employee $emp): PayrollEntry
    {
        return $this->withinTenant($tenant, fn () => PayrollEntry::query()->where('payroll_run_id', $run->getKey())->where('employee_id', $emp->getKey())->firstOrFail());
    }

    public function test_mon_fri_denominator_is_10560(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->monFriEmployee($tenant);
        $this->withinTenant($tenant, fn () => app(EmployeeCompensationService::class)->create($owner, (string) $emp->getKey(), ['currency' => 'JOD', 'base_amount_minor' => 4000000, 'effective_from' => '2020-01-01']));
        $run = $this->runFor($tenant, $owner);

        $entry = $this->entry($tenant, $run, $emp);
        $this->assertSame(self::DENOM, $entry->input_snapshot['schedule']['period_expected_minutes']);
        $this->assertSame(4000000, $entry->gross_minor); // full month = exact monthly
    }

    public function test_mid_hire_golden(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->monFriEmployee($tenant, ['hire_date' => '2026-09-15']);
        $this->withinTenant($tenant, fn () => app(EmployeeCompensationService::class)->create($owner, (string) $emp->getKey(), ['currency' => 'JOD', 'base_amount_minor' => 4000000, 'effective_from' => '2020-01-01']));
        $run = $this->runFor($tenant, $owner);

        $entry = $this->entry($tenant, $run, $emp);
        $this->assertSame(PayrollEntryStatus::Calculated, $entry->status);
        $this->assertSame(2181818, $entry->gross_minor);
        $this->assertSame(0, $entry->deduction_minor);
        $this->assertSame(2181818, $entry->net_minor);
    }

    public function test_termination_golden(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->monFriEmployee($tenant, ['termination_date' => '2026-09-14']);
        $this->withinTenant($tenant, fn () => app(EmployeeCompensationService::class)->create($owner, (string) $emp->getKey(), ['currency' => 'JOD', 'base_amount_minor' => 4000000, 'effective_from' => '2020-01-01']));
        $run = $this->runFor($tenant, $owner);

        $this->assertSame(1818182, $this->entry($tenant, $run, $emp)->gross_minor);
    }

    public function test_complex_jod_golden_case(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->monFriEmployee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $emp, $tenant) {
            app(PayrollSettingsService::class)->update($owner, ['overtime_pay_enabled' => true]);

            // Two adjacent compensation segments; the OT rate lives on the segment
            // covering the overtime date (Sep 17 → segment 2).
            app(EmployeeCompensationService::class)->create($owner, (string) $emp->getKey(), ['currency' => 'JOD', 'base_amount_minor' => 4000000, 'effective_from' => '2020-01-01', 'effective_to' => '2026-09-14']);
            app(EmployeeCompensationService::class)->create($owner, (string) $emp->getKey(), ['currency' => 'JOD', 'base_amount_minor' => 4500000, 'overtime_rate_minor_per_hour' => 30000, 'effective_from' => '2026-09-15']);

            // 12.5% earning component for the whole period.
            $component = app(PayrollComponentService::class)->create($owner, ['code' => 'PCT', 'name' => 'Percent', 'type' => 'earning', 'calculation_mode' => 'percent_of_base']);
            app(EmployeeCompensationComponentService::class)->assign($owner, (string) $emp->getKey(), ['payroll_component_id' => (string) $component->getKey(), 'rate_bps' => 1250, 'effective_from' => '2020-01-01']);

            // Unpaid leave 2026-09-16: coverage 240, consumption deliberately different (480).
            $ltId = (string) Str::ulid();
            DB::table('leave_types')->insert(['id' => $ltId, 'tenant_id' => $tenant->id, 'code' => 'UNP', 'name' => 'Unpaid', 'paid_classification' => 'unpaid', 'created_at' => now(), 'updated_at' => now()]);
            $periodId = (string) Str::ulid();
            DB::table('leave_entitlement_periods')->insert(['id' => $periodId, 'tenant_id' => $tenant->id, 'employee_id' => $emp->getKey(), 'leave_type_id' => $ltId, 'period_type' => 'annual', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
            $reqId = (string) Str::ulid();
            DB::table('leave_requests')->insert(['id' => $reqId, 'tenant_id' => $tenant->id, 'employee_id' => $emp->getKey(), 'leave_type_id' => $ltId, 'entitlement_period_id' => $periodId, 'starts_on' => '2026-09-16', 'ends_on' => '2026-09-16', 'status' => 'approved', 'consumption_basis' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('leave_request_days')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'leave_request_id' => $reqId, 'employee_id' => $emp->getKey(), 'work_date' => '2026-09-16', 'timezone' => 'UTC', 'coverage_minutes' => 240, 'consumption_minutes' => 480, 'consumption_basis' => 'scheduled', 'coverage_intervals' => json_encode([['start_at' => '2026-09-16T08:00:00+00:00', 'end_at' => '2026-09-16T12:00:00+00:00']]), 'created_at' => now()]);

            // Overtime 2026-09-17: approved 90 minutes.
            $recId = (string) Str::ulid();
            DB::table('attendance_records')->insert(['id' => $recId, 'tenant_id' => $tenant->id, 'employee_id' => $emp->getKey(), 'work_date' => '2026-09-17', 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('overtime_approvals')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'attendance_record_id' => $recId, 'employee_id' => $emp->getKey(), 'work_date' => '2026-09-17', 'calculated_minutes' => 90, 'approved_minutes' => 90, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        });

        $run = $this->runFor($tenant, $owner);
        $entry = $this->entry($tenant, $run, $emp);

        $this->assertSame(PayrollEntryStatus::Calculated, $entry->status);
        $this->assertSame('JOD', $entry->currency);
        $this->assertSame(4851818, $entry->gross_minor);
        $this->assertSame(102273, $entry->deduction_minor);
        $this->assertSame(4749545, $entry->net_minor);

        // Exact per-line amounts (as a multiset of code=>amounts).
        $lines = $this->withinTenant($tenant, fn () => PayrollEntryLine::query()->where('payroll_entry_id', $entry->getKey())->orderBy('sort_order')->get());
        $byCode = [];
        foreach ($lines as $l) {
            $byCode[$l->line_code][] = $l->amount_minor;
        }
        sort($byCode['BASE_SALARY']);
        sort($byCode['COMPONENT_EARNING']);
        $this->assertSame([1818182, 2454545], $byCode['BASE_SALARY']);
        $this->assertSame([227273, 306818], $byCode['COMPONENT_EARNING']);
        $this->assertSame([45000], $byCode['OVERTIME']);
        $this->assertSame([102273], $byCode['UNPAID_LEAVE']);
    }
}
