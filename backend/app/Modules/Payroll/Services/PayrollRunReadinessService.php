<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Calculation\PayrollEmployeeCohortService;
use App\Modules\Payroll\Calculation\PayrollStaleInputService;
use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Shared pre-commit readiness checks for a payroll run (Phase 2B): the cohort still
 * matches the persisted entries, every entry calculated cleanly, and no entry's
 * inputs have drifted since calculation. Approval uses these as a gate; finalization
 * re-validates the same invariants authoritatively inside its REPEATABLE READ
 * snapshot (and adds result/line integrity), so a race that passed the gate is still
 * caught at the commit.
 */
class PayrollRunReadinessService
{
    public function __construct(
        private readonly PayrollEmployeeCohortService $cohort,
        private readonly PayrollStaleInputService $stale,
    ) {}

    /**
     * The current cohort's employee ids must EXACTLY equal the run's entry employee
     * ids — a joiner/leaver since calculation requires recalculation first.
     *
     * @param  Collection<int, PayrollEntry>  $entries
     */
    public function assertCohortCurrent(PayrollRun $run, $entries): void
    {
        $run->loadMissing('period');
        $cohortIds = $this->cohort->forPeriod($run->period)
            ->map(fn (Employee $e) => (string) $e->getKey())->sort()->values()->all();
        $entryIds = $entries->map(fn (PayrollEntry $e) => (string) $e->employee_id)->sort()->values()->all();

        if ($cohortIds !== $entryIds) {
            throw ValidationException::withMessages(['run' => [__('payroll.run_cohort_stale')]]);
        }
    }

    /**
     * Every entry must be cleanly calculated — no pending/failed entry may be
     * carried into an approval/finalization.
     *
     * @param  Collection<int, PayrollEntry>  $entries
     */
    public function assertAllCalculated($entries): void
    {
        if ($entries->isEmpty()) {
            throw ValidationException::withMessages(['run' => [__('payroll.run_has_no_entries')]]);
        }
        foreach ($entries as $entry) {
            if ($entry->status !== PayrollEntryStatus::Calculated) {
                throw ValidationException::withMessages(['run' => [__('payroll.run_has_unresolved_entries')]]);
            }
        }
    }

    /**
     * No entry's authoritative inputs may have changed since it was calculated.
     *
     * @param  Collection<int, PayrollEntry>  $entries
     */
    public function assertNoneStale($entries): void
    {
        foreach ($entries as $entry) {
            if ($this->stale->isStale($entry)) {
                throw ValidationException::withMessages(['run' => [__('payroll.run_inputs_stale')]]);
            }
        }
    }
}
