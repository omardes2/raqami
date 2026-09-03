<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollRun;

/**
 * Derives a run summary from its calculated entries. NEVER a scalar mixed-currency
 * total: financial figures are grouped by entry currency. Failed/pending entries
 * are excluded from money totals but always counted in the cohort breakdown.
 */
class PayrollRunSummaryService
{
    /**
     * @return array{by_currency: array<int, array{currency:string, gross_minor:int, deduction_minor:int, net_minor:int, employee_count:int}>, counts: array{cohort:int, calculated:int, failed:int, pending:int}}
     */
    public function summary(PayrollRun $run): array
    {
        $entries = PayrollEntry::query()->where('payroll_run_id', $run->getKey())->get();

        $groups = [];
        $counts = ['cohort' => $entries->count(), 'calculated' => 0, 'failed' => 0, 'pending' => 0];

        foreach ($entries as $entry) {
            match ($entry->status) {
                PayrollEntryStatus::Calculated, PayrollEntryStatus::Finalized => $counts['calculated']++,
                PayrollEntryStatus::Failed => $counts['failed']++,
                PayrollEntryStatus::Pending => $counts['pending']++,
            };

            if (! in_array($entry->status, [PayrollEntryStatus::Calculated, PayrollEntryStatus::Finalized], true) || $entry->currency === null) {
                continue;
            }

            $c = $entry->currency;
            $groups[$c] ??= ['currency' => $c, 'gross_minor' => 0, 'deduction_minor' => 0, 'net_minor' => 0, 'employee_count' => 0];
            $groups[$c]['gross_minor'] += (int) $entry->gross_minor;
            $groups[$c]['deduction_minor'] += (int) $entry->deduction_minor;
            $groups[$c]['net_minor'] += (int) $entry->net_minor;
            $groups[$c]['employee_count']++;
        }

        ksort($groups);

        return ['by_currency' => array_values($groups), 'counts' => $counts];
    }
}
