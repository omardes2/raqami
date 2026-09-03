<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollCalculationService;
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
 * Leave and overtime valuation through the payroll adapters (Sept 2026, 14400
 * expected minutes; a 480-minute day prorates to 10000 at a 300000 base).
 */
class PayrollLeaveOvertimeTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employeeWithBase(Tenant $tenant, $owner, ?int $overtimeRate = null): Employee
    {
        return $this->withinTenant($tenant, function () use ($owner, $overtimeRate) {
            $employee = app(EmployeeService::class)->create(['first_name' => 'L', 'last_name' => 'O', 'employment_status' => 'active', 'hire_date' => '2020-01-01']);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S'.$employee->getKey(), 'code' => 'S'.$employee->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'employee', 'scope_id' => (string) $employee->getKey(), 'effective_from' => '2020-01-01']);
            app(EmployeeCompensationService::class)->create($owner, (string) $employee->getKey(), [
                'currency' => 'USD', 'base_amount_minor' => 300000, 'overtime_rate_minor_per_hour' => $overtimeRate, 'effective_from' => '2020-01-01',
            ]);

            return $employee->fresh();
        });
    }

    private function leaveType(Tenant $tenant, ?string $classification): string
    {
        return $this->withinTenant($tenant, function () use ($tenant, $classification) {
            $id = (string) Str::ulid();
            DB::table('leave_types')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'code' => 'LT'.substr($id, -6), 'name' => 'Leave',
                'paid_classification' => $classification, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        });
    }

    private function approvedLeaveDay(Tenant $tenant, Employee $emp, string $leaveTypeId, string $date): void
    {
        $this->withinTenant($tenant, function () use ($tenant, $emp, $leaveTypeId, $date) {
            $periodId = (string) Str::ulid();
            DB::table('leave_entitlement_periods')->insert([
                'id' => $periodId, 'tenant_id' => $tenant->id, 'employee_id' => $emp->getKey(), 'leave_type_id' => $leaveTypeId,
                'period_type' => 'annual', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $requestId = (string) Str::ulid();
            DB::table('leave_requests')->insert([
                'id' => $requestId, 'tenant_id' => $tenant->id, 'employee_id' => $emp->getKey(), 'leave_type_id' => $leaveTypeId,
                'entitlement_period_id' => $periodId, 'starts_on' => $date, 'ends_on' => $date, 'status' => 'approved',
                'consumption_basis' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('leave_request_days')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'leave_request_id' => $requestId, 'employee_id' => $emp->getKey(),
                'work_date' => $date, 'timezone' => 'UTC', 'coverage_minutes' => 480, 'consumption_minutes' => 999, 'consumption_basis' => 'scheduled',
                'coverage_intervals' => json_encode([['start_at' => $date.'T08:00:00+00:00', 'end_at' => $date.'T16:00:00+00:00']]),
                'created_at' => now(),
            ]);
        });
    }

    private function approvedOvertime(Tenant $tenant, Employee $emp, string $date, int $minutes): void
    {
        $this->withinTenant($tenant, function () use ($tenant, $emp, $date, $minutes) {
            $recordId = (string) Str::ulid();
            DB::table('attendance_records')->insert([
                'id' => $recordId, 'tenant_id' => $tenant->id, 'employee_id' => $emp->getKey(), 'work_date' => $date, 'timezone' => 'UTC',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('overtime_approvals')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'attendance_record_id' => $recordId, 'employee_id' => $emp->getKey(),
                'work_date' => $date, 'calculated_minutes' => $minutes, 'approved_minutes' => $minutes, 'status' => 'approved',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    private function calcRun(Tenant $tenant, $owner): PayrollRun
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

    public function test_paid_leave_creates_no_deduction(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employeeWithBase($tenant, $owner);
        $type = $this->leaveType($tenant, 'paid');
        $this->approvedLeaveDay($tenant, $emp, $type, '2026-09-10');

        $run = $this->calcRun($tenant, $owner);
        $entry = $this->entry($tenant, $run, $emp);
        $this->assertSame(PayrollEntryStatus::Calculated, $entry->status);
        $this->assertSame(300000, $entry->gross_minor);
        $this->assertSame(0, $entry->deduction_minor);
    }

    public function test_unpaid_leave_deducts_from_coverage_not_base_numerator(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employeeWithBase($tenant, $owner);
        $type = $this->leaveType($tenant, 'unpaid');
        $this->approvedLeaveDay($tenant, $emp, $type, '2026-09-10');

        $run = $this->calcRun($tenant, $owner);
        $entry = $this->entry($tenant, $run, $emp);
        // Base is the FULL month (unpaid does not reduce the numerator); unpaid is a
        // separate deduction: 300000 * 480 / 14400 = 10000.
        $this->assertSame(300000, $entry->gross_minor);
        $this->assertSame(10000, $entry->deduction_minor);
        $this->assertSame(290000, $entry->net_minor);
    }

    public function test_unclassified_leave_type_fails_entry(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employeeWithBase($tenant, $owner);
        $type = $this->leaveType($tenant, null);
        $this->approvedLeaveDay($tenant, $emp, $type, '2026-09-10');

        $run = $this->calcRun($tenant, $owner);
        $entry = $this->entry($tenant, $run, $emp);
        $this->assertSame(PayrollEntryStatus::Failed, $entry->status);
        $this->assertSame('unclassified_leave_type', $entry->error_code);
    }

    public function test_overtime_enabled_with_rate_adds_earning(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->withinTenant($tenant, fn () => app(PayrollSettingsService::class)->update($owner, ['overtime_pay_enabled' => true]));
        $emp = $this->employeeWithBase($tenant, $owner, 3000);
        $this->approvedOvertime($tenant, $emp, '2026-09-10', 120);

        $run = $this->calcRun($tenant, $owner);
        $entry = $this->entry($tenant, $run, $emp);
        // 3000/hr * 120m / 60 = 6000
        $this->assertSame(306000, $entry->gross_minor);
    }

    public function test_overtime_disabled_ignores_approved_overtime_and_missing_rate(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        // overtime_pay_enabled defaults false; no OT rate on comp.
        $emp = $this->employeeWithBase($tenant, $owner, null);
        $this->approvedOvertime($tenant, $emp, '2026-09-10', 120);

        $run = $this->calcRun($tenant, $owner);
        $entry = $this->entry($tenant, $run, $emp);
        $this->assertSame(PayrollEntryStatus::Calculated, $entry->status);
        $this->assertSame(300000, $entry->gross_minor);
    }

    public function test_overtime_enabled_missing_rate_fails_entry(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->withinTenant($tenant, fn () => app(PayrollSettingsService::class)->update($owner, ['overtime_pay_enabled' => true]));
        $emp = $this->employeeWithBase($tenant, $owner, null); // enabled but no rate
        $this->approvedOvertime($tenant, $emp, '2026-09-10', 120);

        $run = $this->calcRun($tenant, $owner);
        $entry = $this->entry($tenant, $run, $emp);
        $this->assertSame(PayrollEntryStatus::Failed, $entry->status);
        $this->assertSame('overtime_rate_missing', $entry->error_code);
    }

    public function test_failure_after_success_clears_prior_financial_state(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employeeWithBase($tenant, $owner, null); // OT disabled by default → success
        $run = $this->calcRun($tenant, $owner);
        $entry = $this->entry($tenant, $run, $emp);
        $this->assertSame(PayrollEntryStatus::Calculated, $entry->status);
        $this->withinTenant($tenant, fn () => $this->assertSame(1, DB::table('payroll_entry_lines')->where('payroll_entry_id', $entry->getKey())->count()));

        // Turn the successful entry into a controlled failure: enable OT with an
        // approved shift but no rate, then recalculate.
        $this->withinTenant($tenant, fn () => app(PayrollSettingsService::class)->update($owner, ['overtime_pay_enabled' => true]));
        $this->approvedOvertime($tenant, $emp, '2026-09-10', 120);
        $this->withinTenant($tenant, function () use ($owner, $run) {
            Queue::fake();
            app(PayrollCalculationService::class)->recalculate($owner, $run->fresh());
            app(PayrollCalculationService::class)->execute((string) $run->getKey());
        });

        $this->withinTenant($tenant, function () use ($entry) {
            $fresh = $entry->fresh();
            $this->assertSame(PayrollEntryStatus::Failed, $fresh->status);
            $this->assertSame('overtime_rate_missing', $fresh->error_code);
            $this->assertNull($fresh->currency);
            $this->assertNull($fresh->gross_minor);
            $this->assertNull($fresh->deduction_minor);
            $this->assertNull($fresh->net_minor);
            $this->assertNull($fresh->input_snapshot);
            $this->assertNull($fresh->input_fingerprint);
            $this->assertNull($fresh->calculated_at);
            $this->assertSame(0, DB::table('payroll_entry_lines')->where('payroll_entry_id', $fresh->getKey())->count());
        });
    }
}
