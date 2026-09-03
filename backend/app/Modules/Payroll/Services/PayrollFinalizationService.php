<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Calculation\CalculationResult;
use App\Modules\Payroll\Calculation\PayrollCalculationEngine;
use App\Modules\Payroll\Calculation\PayrollCalculationException;
use App\Modules\Payroll\Calculation\PayrollEmployeeCohortService;
use App\Modules\Payroll\Calculation\PayrollInputBuilder;
use App\Modules\Payroll\Calculation\PayrollInputFingerprintService;
use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Enums\PayrollLineDirection;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollEntryLine;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollSetting;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use App\Modules\Payroll\Support\PayrollFinalizationTransaction;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Payroll finalization (Phase 2B): the authoritative, irreversible financial commit
 * that freezes every entry, finalizes the run, and closes the period — atomically.
 *
 * Finalization owns a TOP-LEVEL REPEATABLE READ transaction and FAILS CLOSED if one
 * is already open (PayrollFinalizationTransaction); it never degrades to a savepoint
 * under an enclosing transaction. Inside that single snapshot, in order: the period
 * and the run are locked FOR UPDATE and re-read as authoritative state (never trusting
 * a model loaded before the snapshot); settings and the current cohort are resolved;
 * each employee's authoritative inputs are rebuilt; then every entry is validated —
 * calculation version, stored-snapshot integrity, current-fingerprint (staleness),
 * a PURE re-run of the frozen engine against persisted totals, and persisted line
 * integrity — before ANY write. Four-eyes, self-payroll, and negative-net override
 * are enforced. Only if EVERY check passes are entries finalized, the run finalized,
 * and the period closed. Exactly-once: a finalized run re-read under the lock is
 * rejected. DB immutability triggers are the last backstop beneath all of this.
 */
class PayrollFinalizationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly PayrollSettingsService $settings,
        private readonly PayrollEmployeeCohortService $cohort,
        private readonly PayrollInputBuilder $builder,
        private readonly PayrollCalculationEngine $engine,
        private readonly PayrollInputFingerprintService $fingerprints,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    public function finalize(User $actor, PayrollRun $run, ?string $negativeNetReason = null): PayrollRun
    {
        // Fast, non-authoritative 403 before opening the commit transaction.
        $this->authz->assertNotSelfPayrollRun($actor, (string) $run->getKey());

        $canOverride = $this->authz->has($actor, 'payroll.negative_override');
        $negativeNetReason = $negativeNetReason !== null ? trim($negativeNetReason) : null;

        // Guarantee the settings row exists (idempotent) BEFORE the snapshot; the
        // authoritative read happens INSIDE the REPEATABLE READ transaction.
        $this->settings->getOrCreate();

        $runId = (string) $run->getKey();
        $periodId = (string) $run->payroll_period_id; // locator only — not authoritative state

        $finalized = PayrollFinalizationTransaction::run(function () use ($actor, $runId, $periodId, $negativeNetReason, $canOverride) {
            // B: lock the period FOR UPDATE, then the run FOR UPDATE, inside the snapshot.
            $period = PayrollPeriod::query()->lockForUpdate()->findOrFail($periodId);
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($runId);

            // Exactly-once + closed-period guards (authoritative state).
            if ($run->status === PayrollRunStatus::Finalized) {
                throw ValidationException::withMessages(['run' => [__('payroll.run_already_finalized')]]);
            }
            if ($period->status === PayrollPeriodStatus::Closed) {
                throw ValidationException::withMessages(['period' => [__('payroll.period_closed')]]);
            }

            // Authoritative settings read inside the snapshot.
            $settings = PayrollSetting::query()->where('tenant_id', $this->context->tenantId())->firstOrFail();
            $fourEyes = (bool) $settings->require_four_eyes;

            // Legal state: four-eyes forces an explicit prior approval.
            $legalFrom = $fourEyes
                ? [PayrollRunStatus::Approved]
                : [PayrollRunStatus::Calculated, PayrollRunStatus::Approved];
            if (! in_array($run->status, $legalFrom, true)) {
                throw ValidationException::withMessages(['run' => [__('payroll.run_not_finalizable')]]);
            }

            // Four-eyes: the finalizer must differ from the approver.
            if ($fourEyes && (string) $run->approved_by_user_id === (string) $actor->getKey()) {
                throw ValidationException::withMessages(['run' => [__('payroll.four_eyes_finalizer')]]);
            }

            // Self-payroll (authoritative, inside the snapshot).
            $this->authz->assertNotSelfPayrollRun($actor, $runId);

            // Authoritative current cohort + persisted entries, all inside the snapshot.
            $cohort = $this->cohort->forPeriod($period);
            $cohortIds = $cohort->map(fn (Employee $e) => (string) $e->getKey())->sort()->values()->all();

            $entries = PayrollEntry::query()->where('payroll_run_id', $runId)->lockForUpdate()->get();
            $entryByEmployee = $entries->keyBy(fn (PayrollEntry $e) => (string) $e->employee_id);
            $entryIds = $entries->map(fn (PayrollEntry $e) => (string) $e->employee_id)->sort()->values()->all();

            if ($entries->isEmpty()) {
                throw ValidationException::withMessages(['run' => [__('payroll.run_has_no_entries')]]);
            }
            if ($cohortIds !== $entryIds) {
                throw ValidationException::withMessages(['run' => [__('payroll.run_cohort_stale')]]);
            }

            // PASS 1 — validate every employee authoritatively. No writes yet.
            $needsOverride = [];
            foreach ($cohort as $employee) {
                /** @var PayrollEntry $entry */
                $entry = $entryByEmployee->get((string) $employee->getKey());

                if ($entry->status !== PayrollEntryStatus::Calculated) {
                    throw ValidationException::withMessages(['run' => [__('payroll.run_has_unresolved_entries')]]);
                }
                if ((string) $entry->calculation_version !== PayrollCalculationEngine::VERSION) {
                    throw ValidationException::withMessages(['run' => [__('payroll.calculation_version_mismatch')]]);
                }
                if ($entry->input_snapshot === null || $entry->input_fingerprint === null
                    || $this->fingerprints->fingerprint($entry->input_snapshot) !== $entry->input_fingerprint) {
                    throw ValidationException::withMessages(['run' => [__('payroll.stored_snapshot_tampered')]]);
                }

                try {
                    $prepared = $this->builder->build($employee, $period, $settings, $run);
                } catch (PayrollCalculationException) {
                    // Inputs no longer produce a valid calculation → drifted since calc.
                    throw ValidationException::withMessages(['run' => [__('payroll.run_inputs_stale')]]);
                }

                if ($this->fingerprints->fingerprint($prepared->snapshot) !== $entry->input_fingerprint) {
                    throw ValidationException::withMessages(['run' => [__('payroll.run_inputs_stale')]]);
                }

                $result = $this->engine->calculate($prepared->input);
                if ($result->currency !== $entry->currency
                    || $result->grossMinor !== (int) $entry->gross_minor
                    || $result->deductionMinor !== (int) $entry->deduction_minor
                    || $result->netMinor !== (int) $entry->net_minor) {
                    throw ValidationException::withMessages(['run' => [__('payroll.result_revalidation_failed')]]);
                }

                $this->assertPersistedLinesConsistent($entry, $result);

                if ((int) $entry->net_minor < 0) {
                    if (! $canOverride || $negativeNetReason === null || $negativeNetReason === '') {
                        throw ValidationException::withMessages(['run' => [__('payroll.negative_net_requires_override')]]);
                    }
                    $needsOverride[(string) $entry->getKey()] = true;
                }
            }

            // PASS 2 — commit: finalize entries → finalize run → close period.
            $now = CarbonImmutable::now()->utc();

            foreach ($entries as $entry) {
                $override = isset($needsOverride[(string) $entry->getKey()]);
                $entry->forceFill(array_merge([
                    'status' => PayrollEntryStatus::Finalized,
                    'finalized_at' => $now,
                    'version' => (int) $entry->version + 1,
                ], $override ? [
                    'negative_net_override_by_user_id' => (string) $actor->getKey(),
                    'negative_net_override_reason' => $negativeNetReason,
                ] : []))->save();
            }

            $run->forceFill([
                'status' => PayrollRunStatus::Finalized,
                'finalized_by_user_id' => (string) $actor->getKey(),
                'finalized_at' => $now,
                'version' => (int) $run->version + 1,
            ])->save();

            $period->forceFill(['status' => PayrollPeriodStatus::Closed])->save();

            return $run->fresh();
        });

        $this->audit->log('payroll.run_finalized', [
            'actor' => $actor, 'subject' => $finalized,
            'metadata' => [
                'payroll_period_id' => $finalized->payroll_period_id,
                'negative_net_override' => $negativeNetReason !== null && $negativeNetReason !== '',
            ],
        ]);

        return $finalized;
    }

    /**
     * The persisted lines must reproduce the entry totals AND match the freshly
     * re-run engine result line-for-line (as a multiset of type|direction|amount) —
     * so a tampered, added, or removed line is caught even if totals coincide.
     */
    private function assertPersistedLinesConsistent(PayrollEntry $entry, CalculationResult $result): void
    {
        $lines = PayrollEntryLine::query()->where('payroll_entry_id', $entry->getKey())->get();

        $gross = 0;
        $deduction = 0;
        $persisted = [];
        foreach ($lines as $line) {
            if ($line->direction === PayrollLineDirection::Earning) {
                $gross += (int) $line->amount_minor;
            } else {
                $deduction += (int) $line->amount_minor;
            }
            $persisted[] = $line->line_type->value.'|'.$line->direction->value.'|'.(int) $line->amount_minor;
        }

        $engine = [];
        foreach ($result->lines as $line) {
            $engine[] = $line->type->value.'|'.$line->direction->value.'|'.$line->amountMinor;
        }

        sort($persisted);
        sort($engine);

        if ($gross !== (int) $entry->gross_minor
            || $deduction !== (int) $entry->deduction_minor
            || $persisted !== $engine) {
            throw ValidationException::withMessages(['run' => [__('payroll.persisted_lines_tampered')]]);
        }
    }
}
