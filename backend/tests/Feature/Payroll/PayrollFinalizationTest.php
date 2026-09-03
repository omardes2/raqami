<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollAdjustmentService;
use App\Modules\Payroll\Services\PayrollApprovalService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollFinalizationService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\PayrollSettingsService;
use App\Modules\Payroll\Support\NestedFinalizationException;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Finalization is the authoritative, irreversible commit. These tests run WITHOUT
 * RefreshDatabase so finalization executes at transaction level 0 — the real
 * top-level REPEATABLE READ path (a RefreshDatabase wrapper would make every
 * finalization nested and correctly rejected). Each test uses its own tenant, so
 * accumulated rows never interfere (everything is tenant-scoped under RLS).
 */
class PayrollFinalizationTest extends TestCase
{
    use InteractsWithTenancy;

    private function employee(Tenant $tenant, $owner, int $base = 300000): Employee
    {
        return $this->withinTenant($tenant, function () use ($owner, $base) {
            $e = app(EmployeeService::class)->create(['first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active', 'hire_date' => '2020-01-01']);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $s = app(WorkScheduleService::class)->create(['name' => 'S'.$e->getKey(), 'code' => 'S'.$e->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($s, ['scope_type' => 'employee', 'scope_id' => (string) $e->getKey(), 'effective_from' => '2020-01-01']);
            app(EmployeeCompensationService::class)->create($owner, (string) $e->getKey(), ['currency' => 'USD', 'base_amount_minor' => $base, 'effective_from' => '2020-01-01']);

            return $e->fresh();
        });
    }

    private function makeRun(Tenant $tenant, $owner): PayrollRun
    {
        return $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']);

            return app(PayrollRunService::class)->create($owner, $period);
        });
    }

    private function calc(Tenant $tenant, $owner, PayrollRun $run, string $method = 'calculate'): void
    {
        $this->withinTenant($tenant, function () use ($owner, $run, $method) {
            Queue::fake();
            app(PayrollCalculationService::class)->{$method}($owner, $run->fresh());
            app(PayrollCalculationService::class)->execute((string) $run->getKey());
        });
    }

    private function finalize(Tenant $tenant, $actor, PayrollRun $run, ?string $reason = null): PayrollRun
    {
        return $this->withinTenant($tenant, fn () => app(PayrollFinalizationService::class)->finalize($actor, $run->fresh(), $reason));
    }

    public function test_finalize_from_calculated_freezes_run_entries_and_closes_period(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        $finalized = $this->finalize($tenant, $owner, $run);

        $this->assertSame(PayrollRunStatus::Finalized, $finalized->status);
        $this->assertSame((string) $owner->getKey(), (string) $finalized->finalized_by_user_id);
        $this->withinTenant($tenant, function () use ($run, $emp) {
            $this->assertSame(PayrollEntryStatus::Finalized, PayrollEntry::query()->where('payroll_run_id', $run->getKey())->where('employee_id', $emp->getKey())->firstOrFail()->status);
            $this->assertSame(PayrollPeriodStatus::Closed, PayrollRun::query()->with('period')->findOrFail($run->getKey())->period->status);
        });
    }

    public function test_exactly_once_second_finalize_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        $this->finalize($tenant, $owner, $run);

        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_four_eyes_requires_prior_approval_before_finalize(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->employee($tenant, $owner);
        $this->withinTenant($tenant, fn () => app(PayrollSettingsService::class)->update($owner, ['require_four_eyes' => true]));
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run); // still calculated, not approved
    }

    public function test_four_eyes_finalizer_must_differ_from_approver(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->employee($tenant, $owner);
        $this->withinTenant($tenant, fn () => app(PayrollSettingsService::class)->update($owner, ['require_four_eyes' => true]));
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        $approver = $this->makeUser();
        $this->withinTenant($tenant, fn () => app(PayrollApprovalService::class)->approve($approver, $run->fresh()));

        // Approver cannot also finalize.
        try {
            $this->finalize($tenant, $approver, $run);
            $this->fail('finalizer == approver must be rejected');
        } catch (ValidationException) {
            // expected
        }

        // A distinct finalizer (the requester) succeeds.
        $finalized = $this->finalize($tenant, $owner, $run);
        $this->assertSame(PayrollRunStatus::Finalized, $finalized->status);
    }

    public function test_negative_net_requires_override_permission_and_reason(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        // A deduction adjustment larger than gross → negative net.
        $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $run->fresh(), (string) $emp->getKey(), [
            'label' => 'Clawback', 'direction' => 'deduction', 'amount_minor' => 400000, 'currency' => 'USD', 'reason' => 'Overpayment',
        ]));
        $this->calc($tenant, $owner, $run, 'recalculate');
        $this->withinTenant($tenant, fn () => $this->assertSame(-100000, PayrollEntry::query()->where('payroll_run_id', $run->getKey())->firstOrFail()->net_minor));

        // An actor without the override permission cannot finalize a negative run.
        $accountant = $this->memberWithRole($tenant, 'accountant');
        try {
            $this->finalize($tenant, $accountant, $run, 'trying');
            $this->fail('negative net without override permission must be rejected');
        } catch (ValidationException) {
            // expected
        }

        // The owner has the override permission; a reason is mandatory and recorded.
        try {
            $this->finalize($tenant, $owner, $run, '');
            $this->fail('negative net without a reason must be rejected');
        } catch (ValidationException) {
            // expected
        }

        $finalized = $this->finalize($tenant, $owner, $run, 'Approved clawback');
        $this->assertSame(PayrollRunStatus::Finalized, $finalized->status);
        $this->withinTenant($tenant, function () use ($run, $owner) {
            $entry = PayrollEntry::query()->where('payroll_run_id', $run->getKey())->firstOrFail();
            $this->assertSame((string) $owner->getKey(), (string) $entry->negative_net_override_by_user_id);
            $this->assertSame('Approved clawback', $entry->negative_net_override_reason);
        });
    }

    public function test_finalize_blocks_a_stale_run(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        // An adjustment raced in after calculation (not folded via recalculate).
        $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $run->fresh(), (string) $emp->getKey(), [
            'label' => 'Late bonus', 'direction' => 'earning', 'amount_minor' => 10000, 'currency' => 'USD', 'reason' => 'x',
        ]));

        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_finalize_blocks_a_cohort_drift(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        $this->employee($tenant, $owner); // new overlapping employee → cohort drift

        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_self_payroll_finalize_is_blocked(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $this->withinTenant($tenant, fn () => $emp->forceFill(['user_id' => (string) $owner->getKey()])->save());
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        $this->expectException(HttpException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_nested_finalization_is_rejected_fail_closed(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        $this->expectException(NestedFinalizationException::class);
        $this->withinTenant($tenant, fn () => DB::transaction(fn () => app(PayrollFinalizationService::class)->finalize($owner, $run->fresh())));
    }

    public function test_finalize_rejects_a_tampered_persisted_line(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        // Tamper a calculated (not yet finalized) line's amount directly.
        $this->withinTenant($tenant, function () use ($run) {
            $entryId = PayrollEntry::query()->where('payroll_run_id', $run->getKey())->value('id');
            DB::table('payroll_entry_lines')->where('payroll_entry_id', $entryId)->where('line_code', 'BASE_SALARY')
                ->update(['amount_minor' => DB::raw('amount_minor + 1')]);
        });

        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_finalize_rejects_tampered_entry_totals(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        $this->withinTenant($tenant, fn () => DB::table('payroll_entries')->where('payroll_run_id', $run->getKey())
            ->update(['gross_minor' => DB::raw('gross_minor + 1000'), 'net_minor' => DB::raw('net_minor + 1000')]));

        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_finalize_rejects_a_tampered_stored_snapshot(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        // Mutate the stored snapshot without updating the fingerprint.
        $this->withinTenant($tenant, function () use ($run) {
            $entry = PayrollEntry::query()->where('payroll_run_id', $run->getKey())->firstOrFail();
            $snapshot = $entry->input_snapshot;
            $snapshot['tampered'] = true;
            DB::table('payroll_entries')->where('id', $entry->getKey())->update(['input_snapshot' => json_encode($snapshot)]);
        });

        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }
}
