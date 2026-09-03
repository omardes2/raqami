<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Audit\Services\AuditLogger;
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
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\PayrollLock;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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
     * Execute the calculation for a run (invoked by the queued job). Idempotent:
     * only runs while the run is `calculating`; per-entry replacement makes retries
     * safe. Each employee is processed in its own transaction so one failure never
     * rolls back another employee's success.
     */
    public function execute(string $runId): void
    {
        $run = PayrollRun::query()->with('period')->find($runId);
        if ($run === null || $run->status !== PayrollRunStatus::Calculating || $run->period === null) {
            return;
        }

        $employees = $this->cohort->forPeriod($run->period);
        $anyFailed = false;

        foreach ($employees as $employee) {
            $entry = PayrollEntry::query()->firstOrCreate(
                ['payroll_run_id' => $run->getKey(), 'employee_id' => $employee->getKey()],
                ['status' => PayrollEntryStatus::Pending, 'version' => 1],
            );

            if ($entry->status === PayrollEntryStatus::Finalized) {
                continue;
            }

            try {
                $prepared = $this->builder->build($employee, $run->period, $this->settings->getOrCreate());
                $result = $this->engine->calculate($prepared->input);
                $fingerprint = $this->fingerprints->fingerprint($prepared->snapshot);
                $this->persistence->persistSuccess($entry, $prepared, $result, $fingerprint);
            } catch (PayrollCalculationException $e) {
                $this->persistence->persistFailure($entry, $e->errorCode, $e->context);
                $anyFailed = true;
            } catch (Throwable $e) {
                // Unexpected error: isolate it as a failed entry (no controlled code).
                $entry->forceFill([
                    'status' => PayrollEntryStatus::Failed,
                    'error_code' => null,
                    'error_context' => ['unexpected' => true, 'message' => substr($e->getMessage(), 0, 120)],
                    'currency' => null, 'gross_minor' => null, 'deduction_minor' => null, 'net_minor' => null,
                    'input_snapshot' => null, 'input_fingerprint' => null, 'employee_snapshot' => null,
                    'version' => (int) $entry->version + 1,
                ])->save();
                $anyFailed = true;
            }
        }

        $finalStatus = $anyFailed ? PayrollRunStatus::CalculationFailed : PayrollRunStatus::Calculated;

        DB::transaction(function () use ($run, $finalStatus) {
            $locked = PayrollRun::query()->lockForUpdate()->find($run->getKey());
            if ($locked === null || $locked->status !== PayrollRunStatus::Calculating) {
                return;
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
                'cohort' => $employees->count(),
                'status' => $finalStatus->value,
                'calculation_version' => PayrollCalculationEngine::VERSION,
            ],
        ]);
    }
}
