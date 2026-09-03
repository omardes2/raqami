<?php

namespace App\Modules\Payroll\Calculation;

use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Enums\PayrollErrorCode;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollEntryLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persists a calculated (or failed) entry with REPLACEMENT of its generated lines —
 * never incremental appends. A finalized entry is never touched (finalization is a
 * later phase). On failure, any prior financial result and lines are cleared so a
 * stale success can never survive a later failure.
 *
 * writeSuccess assumes the caller already holds the coherent (REPEATABLE READ)
 * transaction and a lock on the entry — the calculation orchestrator writes inside
 * the same snapshot it read. persistSuccess/persistFailure are transactional
 * wrappers for callers that manage no transaction of their own.
 */
class PayrollEntryPersistenceService
{
    /** Write a successful result. Assumes an open transaction with $entry already locked. */
    public function writeSuccess(PayrollEntry $entry, PreparedCalculation $prepared, CalculationResult $result, string $fingerprint): PayrollEntry
    {
        if ($entry->status === PayrollEntryStatus::Finalized) {
            return $entry; // never mutate finalized rows
        }

        PayrollEntryLine::query()->where('payroll_entry_id', $entry->getKey())->delete();

        $sort = 0;
        foreach ($result->lines as $line) {
            PayrollEntryLine::query()->create([
                'payroll_entry_id' => $entry->getKey(),
                'line_code' => $line->type->value,
                'line_type' => $line->type,
                'direction' => $line->direction,
                'source_type' => $line->sourceType,
                'source_id' => $line->sourceId,
                'label_snapshot' => $line->label,
                'quantity_minutes' => $line->quantityMinutes,
                'rate_minor_per_hour' => $line->rateMinorPerHour,
                'rate_bps' => $line->rateBps,
                'amount_minor' => $line->amountMinor,
                'metadata' => $line->metadata !== [] ? $line->metadata : null,
                'sort_order' => $sort++,
                'created_at' => CarbonImmutable::now()->utc(),
            ]);
        }

        $entry->forceFill([
            'currency' => $result->currency,
            'status' => PayrollEntryStatus::Calculated,
            'employee_snapshot' => $prepared->employeeSnapshot,
            'input_snapshot' => $prepared->snapshot,
            'input_fingerprint' => $fingerprint,
            'gross_minor' => $result->grossMinor,
            'deduction_minor' => $result->deductionMinor,
            'net_minor' => $result->netMinor,
            'calculation_version' => PayrollCalculationEngine::VERSION,
            'calculated_at' => CarbonImmutable::now()->utc(),
            'error_code' => null,
            'error_context' => null,
            'version' => (int) $entry->version + 1,
        ])->save();

        return $entry->fresh();
    }

    public function persistSuccess(PayrollEntry $entry, PreparedCalculation $prepared, CalculationResult $result, string $fingerprint): PayrollEntry
    {
        return DB::transaction(function () use ($entry, $prepared, $result, $fingerprint) {
            $locked = PayrollEntry::query()->lockForUpdate()->findOrFail($entry->getKey());

            return $this->writeSuccess($locked, $prepared, $result, $fingerprint);
        });
    }

    /** @param array<string, scalar|null> $context */
    public function persistFailure(PayrollEntry $entry, PayrollErrorCode $code, array $context): PayrollEntry
    {
        return DB::transaction(function () use ($entry, $code, $context) {
            $entry = PayrollEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            if ($entry->status === PayrollEntryStatus::Finalized) {
                return $entry;
            }

            PayrollEntryLine::query()->where('payroll_entry_id', $entry->getKey())->delete();

            $entry->forceFill([
                'currency' => null,
                'status' => PayrollEntryStatus::Failed,
                'employee_snapshot' => null,
                'input_snapshot' => null,
                'input_fingerprint' => null,
                'gross_minor' => null,
                'deduction_minor' => null,
                'net_minor' => null,
                'calculation_version' => PayrollCalculationEngine::VERSION,
                'calculated_at' => null,
                'error_code' => $code->value,
                'error_context' => $context !== [] ? $context : null,
                'version' => (int) $entry->version + 1,
            ])->save();

            return $entry->fresh();
        });
    }
}
