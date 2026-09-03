<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationComponentService;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollAdjustmentService;
use App\Modules\Payroll\Services\PayrollApprovalService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollComponentService;
use App\Modules\Payroll\Services\PayrollFinalizationService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\PayrollSettingsService;
use App\Modules\Payroll\Support\NestedFinalizationException;
use App\Modules\Payroll\Support\PayrollRunExecutionLock;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDO;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CommitsPayrollAtTopLevel;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Finalization is the authoritative, irreversible commit. Runs WITHOUT RefreshDatabase
 * so it executes at transaction level 0 — the real top-level REPEATABLE READ path.
 */
class PayrollFinalizationTest extends TestCase
{
    use CommitsPayrollAtTopLevel {
        tearDown as protected cleanupCommittedTenants;
    }
    use InteractsWithTenancy;

    private ?PayrollPeriod $period = null;

    private ?PDO $other = null;

    protected function tearDown(): void
    {
        if ($this->other !== null) {
            try {
                $this->other->exec('SELECT pg_advisory_unlock_all()');
            } catch (\Throwable) {
            }
            $this->other = null;
        }
        // Runs the committed-tenant cleanup, which itself calls parent::tearDown().
        $this->cleanupCommittedTenants();
    }

    private function employee(Tenant $tenant, $owner, bool $withPercentComponent = false): Employee
    {
        return $this->withinTenant($tenant, function () use ($owner, $withPercentComponent) {
            $e = app(EmployeeService::class)->create(['first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active', 'hire_date' => '2020-01-01']);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $s = app(WorkScheduleService::class)->create(['name' => 'S'.$e->getKey(), 'code' => 'S'.$e->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($s, ['scope_type' => 'employee', 'scope_id' => (string) $e->getKey(), 'effective_from' => '2020-01-01']);
            app(EmployeeCompensationService::class)->create($owner, (string) $e->getKey(), ['currency' => 'USD', 'base_amount_minor' => 300000, 'effective_from' => '2020-01-01']);
            if ($withPercentComponent) {
                $c = app(PayrollComponentService::class)->create($owner, ['code' => 'PCT', 'name' => 'Percent', 'type' => 'earning', 'calculation_mode' => 'percent_of_base']);
                app(EmployeeCompensationComponentService::class)->assign($owner, (string) $e->getKey(), ['payroll_component_id' => (string) $c->getKey(), 'rate_bps' => 1000, 'effective_from' => '2020-01-01']);
            }

            return $e->fresh();
        });
    }

    private function makeRun(Tenant $tenant, $owner): PayrollRun
    {
        return $this->withinTenant($tenant, function () use ($owner) {
            $this->period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']);

            return app(PayrollRunService::class)->create($owner, $this->period);
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

    private function adjust(Tenant $tenant, $owner, Employee $emp, array $overrides = []): void
    {
        $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $this->period->fresh(), (string) $emp->getKey(), array_merge([
            'employee_visible_label' => 'Adj', 'direction' => 'earning', 'amount_minor' => 10000, 'currency' => 'USD', 'internal_reason' => 'r',
        ], $overrides)));
    }

    private function finalize(Tenant $tenant, $actor, PayrollRun $run, ?string $reason = null): PayrollRun
    {
        return $this->withinTenant($tenant, fn () => app(PayrollFinalizationService::class)->finalize($actor, $run->fresh(), $reason));
    }

    public function test_finalize_from_calculated_freezes_and_closes_period(): void
    {
        [$owner, $tenant] = $this->trackedCompany();
        $emp = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        $finalized = $this->finalize($tenant, $owner, $run);
        $this->assertSame(PayrollRunStatus::Finalized, $finalized->status);
        $this->withinTenant($tenant, function () use ($run, $emp) {
            $this->assertSame(PayrollEntryStatus::Finalized, PayrollEntry::query()->where('payroll_run_id', $run->getKey())->where('employee_id', $emp->getKey())->firstOrFail()->status);
            $this->assertSame(PayrollPeriodStatus::Closed, PayrollRun::query()->with('period')->findOrFail($run->getKey())->period->status);
        });
    }

    public function test_exactly_once_second_finalize_rejected(): void
    {
        [$owner, $tenant] = $this->trackedCompany();
        $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        $this->finalize($tenant, $owner, $run);

        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_four_eyes_requires_prior_approval(): void
    {
        [$owner, $tenant] = $this->trackedCompany();
        $this->employee($tenant, $owner);
        $this->withinTenant($tenant, fn () => app(PayrollSettingsService::class)->update($owner, ['require_four_eyes' => true]));
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_four_eyes_approver_may_finalize(): void
    {
        [$owner, $tenant] = $this->trackedCompany();
        $this->employee($tenant, $owner);
        $this->withinTenant($tenant, fn () => app(PayrollSettingsService::class)->update($owner, ['require_four_eyes' => true]));
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        $approver = $this->makeUser();
        $this->withinTenant($tenant, fn () => app(PayrollApprovalService::class)->approve($approver, $run->fresh()));

        // The approver (≠ requester) may also finalize — no third person required.
        $finalized = $this->finalize($tenant, $approver, $run);
        $this->assertSame(PayrollRunStatus::Finalized, $finalized->status);
    }

    public function test_negative_net_requires_override_and_records_it(): void
    {
        [$owner, $tenant] = $this->trackedCompany();
        $emp = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        $this->adjust($tenant, $owner, $emp, ['direction' => 'deduction', 'amount_minor' => 400000, 'internal_reason' => 'Overpayment']);
        $this->calc($tenant, $owner, $run, 'recalculate');
        $this->withinTenant($tenant, fn () => $this->assertSame(-100000, PayrollEntry::query()->where('payroll_run_id', $run->getKey())->firstOrFail()->net_minor));

        $accountant = $this->memberWithRole($tenant, 'accountant');
        try {
            $this->finalize($tenant, $accountant, $run, 'x');
            $this->fail('no override permission must be rejected');
        } catch (ValidationException) {
        }
        try {
            $this->finalize($tenant, $owner, $run, '');
            $this->fail('missing reason must be rejected');
        } catch (ValidationException) {
        }

        $finalized = $this->finalize($tenant, $owner, $run, 'Approved clawback');
        $this->assertSame(PayrollRunStatus::Finalized, $finalized->status);
        $this->withinTenant($tenant, function () use ($run, $owner) {
            $entry = PayrollEntry::query()->where('payroll_run_id', $run->getKey())->firstOrFail();
            $this->assertSame((string) $owner->getKey(), (string) $entry->negative_net_override_by_user_id);
            $this->assertSame('Approved clawback', $entry->negative_net_override_reason);
        });
    }

    public function test_finalize_blocks_stale_and_cohort_drift(): void
    {
        [$owner, $tenant] = $this->trackedCompany();
        $emp = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        $this->adjust($tenant, $owner, $emp); // stale
        try {
            $this->finalize($tenant, $owner, $run);
            $this->fail('stale must be rejected');
        } catch (ValidationException) {
        }

        [$owner2, $tenant2] = $this->trackedCompany();
        $this->employee($tenant2, $owner2);
        $run2 = $this->makeRun($tenant2, $owner2);
        $this->calc($tenant2, $owner2, $run2);
        $this->employee($tenant2, $owner2); // cohort drift
        $this->expectException(ValidationException::class);
        $this->finalize($tenant2, $owner2, $run2);
    }

    public function test_self_payroll_finalize_blocked(): void
    {
        [$owner, $tenant] = $this->trackedCompany();
        $emp = $this->employee($tenant, $owner);
        $this->withinTenant($tenant, fn () => $emp->forceFill(['user_id' => (string) $owner->getKey()])->save());
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        $this->expectException(HttpException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_nested_finalization_is_rejected_fail_closed(): void
    {
        [$owner, $tenant] = $this->trackedCompany();
        $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        $this->expectException(NestedFinalizationException::class);
        $this->withinTenant($tenant, fn () => DB::transaction(fn () => app(PayrollFinalizationService::class)->finalize($owner, $run->fresh())));
    }

    public function test_calculation_lock_blocks_finalization(): void
    {
        [$owner, $tenant] = $this->trackedCompany();
        $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        // Another worker owns the run-execution lock.
        $c = config('database.connections.pgsql');
        $this->other = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $ns = PayrollRunExecutionLock::namespace($tenant->id, (string) $run->getKey());
        $this->other->prepare('SELECT pg_advisory_lock(hashtextextended(?, 0))')->execute([$ns]);

        try {
            $this->finalize($tenant, $owner, $run);
            $this->fail('finalization must be blocked while calculation holds the lock');
        } catch (ValidationException) {
        }
        $this->withinTenant($tenant, function () use ($run) {
            $this->assertSame(PayrollRunStatus::Calculated, PayrollRun::query()->findOrFail($run->getKey())->status);
            $this->assertSame(PayrollPeriodStatus::Open, PayrollRun::query()->with('period')->findOrFail($run->getKey())->period->status);
        });

        // Free the lock → finalization proceeds.
        $this->other->exec('SELECT pg_advisory_unlock_all()');
        $this->other = null;
        $this->assertSame(PayrollRunStatus::Finalized, $this->finalize($tenant, $owner, $run)->status);
    }

    /** @return array{0:mixed,1:Tenant,2:PayrollRun} */
    private function calculatedFor(bool $percent = false): array
    {
        [$owner, $tenant] = $this->trackedCompany();
        $this->employee($tenant, $owner, $percent);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        return [$owner, $tenant, $run];
    }

    public function test_finalize_rejects_amount_tamper(): void
    {
        [$owner, $tenant, $run] = $this->calculatedFor();
        $this->withinTenant($tenant, fn () => DB::table('payroll_entry_lines')
            ->where('payroll_entry_id', PayrollEntry::query()->where('payroll_run_id', $run->getKey())->value('id'))
            ->where('line_code', 'BASE_SALARY')->update(['amount_minor' => DB::raw('amount_minor + 1')]));
        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_finalize_rejects_totals_tamper(): void
    {
        [$owner, $tenant, $run] = $this->calculatedFor();
        $this->withinTenant($tenant, fn () => DB::table('payroll_entries')->where('payroll_run_id', $run->getKey())
            ->update(['gross_minor' => DB::raw('gross_minor + 1000'), 'net_minor' => DB::raw('net_minor + 1000')]));
        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_finalize_rejects_snapshot_tamper(): void
    {
        [$owner, $tenant, $run] = $this->calculatedFor();
        $this->withinTenant($tenant, function () use ($run) {
            $entry = PayrollEntry::query()->where('payroll_run_id', $run->getKey())->firstOrFail();
            $snap = $entry->input_snapshot;
            $snap['tampered'] = true;
            DB::table('payroll_entries')->where('id', $entry->getKey())->update(['input_snapshot' => json_encode($snap)]);
        });
        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_finalize_rejects_source_id_tamper(): void
    {
        [$owner, $tenant, $run] = $this->calculatedFor();
        $this->withinTenant($tenant, fn () => DB::table('payroll_entry_lines')
            ->where('payroll_entry_id', PayrollEntry::query()->where('payroll_run_id', $run->getKey())->value('id'))
            ->where('line_code', 'BASE_SALARY')->update(['source_id' => (string) Str::ulid()]));
        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_finalize_rejects_quantity_tamper(): void
    {
        [$owner, $tenant, $run] = $this->calculatedFor();
        $this->withinTenant($tenant, fn () => DB::table('payroll_entry_lines')
            ->where('payroll_entry_id', PayrollEntry::query()->where('payroll_run_id', $run->getKey())->value('id'))
            ->where('line_code', 'BASE_SALARY')->update(['quantity_minutes' => DB::raw('quantity_minutes + 5')]));
        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_finalize_rejects_rate_bps_tamper(): void
    {
        [$owner, $tenant, $run] = $this->calculatedFor(percent: true);
        $this->withinTenant($tenant, fn () => DB::table('payroll_entry_lines')
            ->where('payroll_entry_id', PayrollEntry::query()->where('payroll_run_id', $run->getKey())->value('id'))
            ->where('line_code', 'COMPONENT_EARNING')->update(['rate_bps' => 9999]));
        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }

    public function test_finalize_rejects_label_tamper(): void
    {
        [$owner, $tenant, $run] = $this->calculatedFor();
        $this->withinTenant($tenant, fn () => DB::table('payroll_entry_lines')
            ->where('payroll_entry_id', PayrollEntry::query()->where('payroll_run_id', $run->getKey())->value('id'))
            ->where('line_code', 'BASE_SALARY')->update(['label_snapshot' => 'Tampered']));
        $this->expectException(ValidationException::class);
        $this->finalize($tenant, $owner, $run);
    }
}
