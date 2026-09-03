<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Calculation\PayrollCalculationEngine;
use App\Modules\Payroll\Calculation\PayrollCalculationException;
use App\Modules\Payroll\Calculation\PayrollEmployeeCohortService;
use App\Modules\Payroll\Calculation\PayrollEntryPersistenceService;
use App\Modules\Payroll\Calculation\PayrollInputBuilder;
use App\Modules\Payroll\Calculation\PayrollInputFingerprintService;
use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Jobs\PayrollCalculationJob;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollEntryLine;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\PayrollEntryTransaction;
use App\Modules\Payroll\Support\PayrollLock;
use App\Modules\Payroll\Support\PayrollRunExecutionLock;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates payroll calculation (Phase 2A): the request side transitions the run
 * to `calculating` under a row lock + advisory lock and dispatches a TenantAware job;
 * the execution side builds each employee's authoritative input, runs the pure engine
 * in a per-entry transaction (failure isolation), and settles the run status. No
 * approval/finalization here — those are a later phase.
 */
class PayrollCalculationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly PayrollEmployeeCohortService $cohort,
        private readonly PayrollInputBuilder $builder,
        private readonly PayrollCalculationEngine $engine,
        private readonly PayrollInputFingerprintService $fingerprints,
        private readonly PayrollEntryPersistenceService $persistence,
        private readonly PayrollSettingsService $settings,
    ) {}

    /** Legal from draft or calculation_failed. */
    public function calculate(User $actor, PayrollRun $run): PayrollRun
    {
        return $this->request($actor, $run, [PayrollRunStatus::Draft, PayrollRunStatus::CalculationFailed], 'payroll.calculation_requested');
    }

    /** Legal from calculated or calculation_failed. */
    public function recalculate(User $actor, PayrollRun $run): PayrollRun
    {
        return $this->request($actor, $run, [PayrollRunStatus::Calculated, PayrollRunStatus::CalculationFailed], 'payroll.recalculation_requested');
    }

    /**
     * @param  array<int, PayrollRunStatus>  $legalFrom
     */
    private function request(User $actor, PayrollRun $run, array $legalFrom, string $auditAction): PayrollRun
    {
        $run = DB::transaction(function () use ($actor, $run, $legalFrom) {
            PayrollLock::forPeriodRun((string) $this->context->tenantId(), (string) $run->payroll_period_id);
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->getKey());

            if ($run->status === PayrollRunStatus::Calculating) {
                throw ValidationException::withMessages(['run' => [__('payroll.run_calculation_in_progress')]]);
            }
            if (! in_array($run->status, $legalFrom, true)) {
                throw ValidationException::withMessages(['run' => [__('payroll.run_not_calculable')]]);
            }

            $run->forceFill([
                'status' => PayrollRunStatus::Calculating,
                'calculation_requested_by_user_id' => (string) $actor->getKey(),
                'calculation_version' => PayrollCalculationEngine::VERSION,
                'version' => (int) $run->version + 1,
            ])->save();

            return $run->fresh();
        });

        $this->audit->log($auditAction, [
            'actor' => $actor, 'subject' => $run,
            'metadata' => ['payroll_period_id' => $run->payroll_period_id, 'calculation_version' => PayrollCalculationEngine::VERSION],
        ]);

        PayrollCalculationJob::dispatch((string) $run->getKey())->afterCommit();

        return $run;
    }

    /**
     * Execute the calculation for a run (invoked by the queued job). Holds an
     * EXCLUSIVE session-level advisory lock for the whole job so at most one worker
     * ever processes a run (a duplicate delivery no-ops). Reconciles the cohort
     * (obsolete non-finalized entries removed), then calculates each employee inside
     * its own REPEATABLE READ snapshot; one employee's controlled failure never rolls
     * back another's success. Idempotent — a retry after a released lock re-runs safely.
     */
    public function execute(string $runId): void
    {
        $tenantId = (string) $this->context->tenantId();

        // B-3: exactly one active worker per run. A duplicate worker exits silently.
        if (! PayrollRunExecutionLock::tryAcquire($tenantId, $runId)) {
            return;
        }

        try {
            $run = PayrollRun::query()->with('period')->find($runId);
            if ($run === null || $run->status !== PayrollRunStatus::Calculating || $run->period === null) {
                return; // nothing to do / a duplicate that arrived after settlement
            }

            $employees = $this->cohort->forPeriod($run->period);
            $cohortIds = $employees->map(fn (Employee $e) => (string) $e->getKey())->all();

            $this->reconcileCohort($run, $cohortIds);

            $anyFailed = false;
            foreach ($employees as $employee) {
                if ($this->calculateEmployee($run, $employee) === 'failed') {
                    $anyFailed = true;
                }
            }

            $finalStatus = $anyFailed ? PayrollRunStatus::CalculationFailed : PayrollRunStatus::Calculated;

            DB::transaction(function () use ($run, $finalStatus) {
                $locked = PayrollRun::query()->lockForUpdate()->find($run->getKey());
                if ($locked === null || $locked->status !== PayrollRunStatus::Calculating) {
                    return; // never settle a cancelled/finalized/unexpected state
                }
                $locked->forceFill([
                    'status' => $finalStatus,
                    'calculated_at' => CarbonImmutable::now()->utc(),
                    'version' => (int) $locked->version + 1,
                ])->save();
            });

            $this->audit->log($anyFailed ? 'payroll.calculation_failed' : 'payroll.calculation_completed', [
                'subject' => $run,
                'metadata' => [
                    'payroll_period_id' => $run->payroll_period_id,
                    'cohort' => count($cohortIds),
                    'status' => $finalStatus->value,
                    'calculation_version' => PayrollCalculationEngine::VERSION,
                ],
            ]);
        } finally {
            PayrollRunExecutionLock::release($tenantId, $runId);
        }
    }

    /**
     * B-1: remove obsolete NON-FINALIZED entries whose employee is no longer in the
     * cohort, so they cannot contribute to the summary or be finalized later. Runs
     * while this worker owns the run-execution lock, so no second worker can race it.
     *
     * @param  array<int, string>  $cohortIds
     */
    private function reconcileCohort(PayrollRun $run, array $cohortIds): void
    {
        DB::transaction(function () use ($run, $cohortIds) {
            $obsolete = PayrollEntry::query()
                ->where('payroll_run_id', $run->getKey())
                ->where('status', '!=', PayrollEntryStatus::Finalized->value)
                ->when($cohortIds !== [], fn ($q) => $q->whereNotIn('employee_id', $cohortIds))
                ->lockForUpdate()
                ->get();

            foreach ($obsolete as $entry) {
                PayrollEntryLine::query()->where('payroll_entry_id', $entry->getKey())->delete();
                $entry->delete();
            }
        });
    }

    /**
     * Calculate one employee inside a single coherent REPEATABLE READ snapshot
     * (B-2): all authoritative reads and the write share one database moment. A
     * controlled failure is recorded separately (the snapshot transaction has already
     * rolled back, leaving no partial financial state).
     *
     * @return 'calculated'|'failed'
     */
    private function calculateEmployee(PayrollRun $run, Employee $employee): string
    {
        $entry = PayrollEntry::query()->firstOrCreate(
            ['payroll_run_id' => $run->getKey(), 'employee_id' => $employee->getKey()],
            ['status' => PayrollEntryStatus::Pending, 'version' => 1],
        );

        if ($entry->status === PayrollEntryStatus::Finalized) {
            return 'calculated';
        }

        try {
            PayrollEntryTransaction::run(function () use ($run, $employee, $entry) {
                $locked = PayrollEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
                if ($locked->status === PayrollEntryStatus::Finalized) {
                    return;
                }
                // Reload the employee (incl. soft-deleted historical rows) and settings
                // INSIDE the snapshot so every fact is read at the same moment.
                $fresh = Employee::withTrashed()->findOrFail($employee->getKey());
                $prepared = $this->builder->build($fresh, $run->period, $this->settings->getOrCreate(), $run);
                $result = $this->engine->calculate($prepared->input);
                $fingerprint = $this->fingerprints->fingerprint($prepared->snapshot);
                $this->persistence->writeSuccess($locked, $prepared, $result, $fingerprint);
            });

            return 'calculated';
        } catch (PayrollCalculationException $e) {
            $this->persistence->persistFailure($entry, $e->errorCode, $e->context);

            return 'failed';
        }
    }
}
