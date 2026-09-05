<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Database\Eloquent\Builder;

/**
 * Company-wide payroll reporting (Sprint 8A Phase 2), READ-ONLY over immutable
 * FINALIZED history only. Financial figures are ALWAYS grouped by entry currency
 * and NEVER summed across currencies — there is no base currency, no FX, no scalar
 * grand total. All money stays integer minor units; aggregation is done in SQL.
 * Authorization (company-wide payroll.reports.view) is enforced by the controller
 * via PayrollAuthorizationService; RLS keeps every query inside the tenant.
 *
 * Financial source is strictly: payroll_entries.status = finalized AND parent
 * payroll_runs.status = finalized AND parent payroll_periods.status = closed.
 * No draft/calculating/failed/calculated/approved/cancelled/replacement money ever
 * enters a financial total. Live payroll_adjustments are never read — the finalized
 * effect lives in the immutable payroll_entry_lines.
 */
class PayrollReportService
{
    /** Max distinct periods returned by the period report (bounded, no lifetime dump). */
    private const MAX_PERIODS = 24;

    /**
     * Max DISTINCT employees returned by the by-employee report. This bounds the
     * employee POPULATION at the database (a SQL LIMIT on distinct employee ids),
     * not the number of rows: an employee paid in JOD and USD is ONE employee and
     * still contributes both per-currency rows. The cap is applied before any large
     * aggregate is materialized, so the report never loads all-history company-wide.
     */
    private const MAX_EMPLOYEES = 500;

    /** Base query over finalized entries whose run is finalized and period closed. */
    private function finalized(): Builder
    {
        return PayrollEntry::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_entries.payroll_run_id')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_runs.payroll_period_id')
            ->where('payroll_entries.status', PayrollEntryStatus::Finalized->value)
            ->where('payroll_runs.status', PayrollRunStatus::Finalized->value)
            ->where('payroll_periods.status', PayrollPeriodStatus::Closed->value);
    }

    /** @param  array{payroll_period_id?:?string, employee_id?:?string, currency?:?string}  $f */
    private function applyFilters(Builder $q, array $f): Builder
    {
        if (! empty($f['payroll_period_id'])) {
            $q->where('payroll_runs.payroll_period_id', $f['payroll_period_id']);
        }
        if (! empty($f['employee_id'])) {
            $q->where('payroll_entries.employee_id', $f['employee_id']);
        }
        if (! empty($f['currency'])) {
            $q->where('payroll_entries.currency', $f['currency']);
        }

        return $q;
    }

    /**
     * Finalized totals grouped by currency (never combined). entry_count is a plain
     * row count (currency-neutral) and may be summed; money never is.
     *
     * @param  array{payroll_period_id?:?string, employee_id?:?string, currency?:?string}  $f
     * @return array<string, mixed>
     */
    public function summary(array $f): array
    {
        $rows = $this->applyFilters($this->finalized(), $f)
            ->selectRaw('payroll_entries.currency as currency')
            ->selectRaw('count(*) as entry_count')
            ->selectRaw('count(distinct payroll_entries.employee_id) as employee_count')
            ->selectRaw('coalesce(sum(payroll_entries.gross_minor),0) as gross_minor')
            ->selectRaw('coalesce(sum(payroll_entries.deduction_minor),0) as deduction_minor')
            ->selectRaw('coalesce(sum(payroll_entries.net_minor),0) as net_minor')
            ->groupBy('payroll_entries.currency')
            ->orderBy('payroll_entries.currency')
            ->get();

        return [
            'entry_count' => (int) $rows->sum('entry_count'),
            'currencies' => $rows->pluck('currency')->all(),
            'by_currency' => $rows->map(fn ($r) => [
                'currency' => $r->currency,
                'entry_count' => (int) $r->entry_count,
                'employee_count' => (int) $r->employee_count,
                'gross_minor' => (int) $r->gross_minor,
                'deduction_minor' => (int) $r->deduction_minor,
                'net_minor' => (int) $r->net_minor,
            ])->all(),
        ];
    }

    /**
     * Finalized totals per closed period, grouped by currency within each period,
     * newest period first, bounded to the most recent MAX_PERIODS.
     *
     * @param  array{employee_id?:?string, currency?:?string}  $f
     * @return array<int, array<string, mixed>>
     */
    public function byPeriod(array $f): array
    {
        // Most-recent finalized periods (bounded), newest first.
        $periodIds = $this->finalized()
            ->selectRaw('payroll_periods.id as pid, max(payroll_periods.period_start) as ps')
            ->groupBy('payroll_periods.id')
            ->orderByDesc('ps')
            ->limit(self::MAX_PERIODS)
            ->pluck('pid');

        if ($periodIds->isEmpty()) {
            return [];
        }

        $rows = $this->applyFilters($this->finalized(), $f)
            ->whereIn('payroll_runs.payroll_period_id', $periodIds->all())
            ->selectRaw('payroll_periods.id as period_id, payroll_periods.label as label, payroll_periods.period_start as period_start, payroll_periods.period_end as period_end')
            ->selectRaw('payroll_entries.currency as currency')
            ->selectRaw('count(distinct payroll_entries.employee_id) as employee_count')
            ->selectRaw('coalesce(sum(payroll_entries.gross_minor),0) as gross_minor')
            ->selectRaw('coalesce(sum(payroll_entries.deduction_minor),0) as deduction_minor')
            ->selectRaw('coalesce(sum(payroll_entries.net_minor),0) as net_minor')
            ->groupBy('payroll_periods.id', 'payroll_periods.label', 'payroll_periods.period_start', 'payroll_periods.period_end', 'payroll_entries.currency')
            ->orderByDesc('payroll_periods.period_start')
            ->orderBy('payroll_entries.currency')
            ->get();

        $periods = [];
        foreach ($rows as $r) {
            $periods[$r->period_id] ??= [
                'period_id' => (string) $r->period_id,
                'label' => $r->label,
                'period_start' => $r->period_start ? substr((string) $r->period_start, 0, 10) : null,
                'period_end' => $r->period_end ? substr((string) $r->period_end, 0, 10) : null,
                'by_currency' => [],
            ];
            $periods[$r->period_id]['by_currency'][] = [
                'currency' => $r->currency,
                'employee_count' => (int) $r->employee_count,
                'gross_minor' => (int) $r->gross_minor,
                'deduction_minor' => (int) $r->deduction_minor,
                'net_minor' => (int) $r->net_minor,
            ];
        }

        // Preserve newest-first period order from $periodIds.
        return $periodIds->map(fn ($id) => $periods[$id] ?? null)->filter()->values()->all();
    }

    /**
     * Finalized totals per employee, grouped by currency, using the finalized
     * employee_snapshot for safe historical identity (employee_number, name).
     *
     * SQL-bounded before materialization: a first query selects at most
     * MAX_EMPLOYEES DISTINCT eligible employee ids with a database LIMIT (stable
     * employee_id order — never a mutable salary ranking), and both the aggregate
     * and the identity lookup are then confined to that bounded id set. The report
     * therefore never fetches the whole company-wide finalized population into PHP.
     * Filters are applied to the finalized population BEFORE the limit, so an
     * employee_id filter targets that employee directly rather than being applied
     * after an arbitrary 500-employee cut.
     *
     * @param  array{payroll_period_id?:?string, employee_id?:?string, currency?:?string}  $f
     * @return array<int, array<string, mixed>>
     */
    public function byEmployee(array $f): array
    {
        // Step 1 — bound the employee POPULATION at the database: at most
        // MAX_EMPLOYEES distinct employee ids (one per employee, whatever their
        // currency count), deterministic by employee_id, with a SQL LIMIT.
        $employeeIds = $this->applyFilters($this->finalized(), $f)
            ->select('payroll_entries.employee_id')
            ->distinct()
            ->orderBy('payroll_entries.employee_id')
            ->limit(self::MAX_EMPLOYEES)
            ->pluck('payroll_entries.employee_id')
            ->all();

        if ($employeeIds === []) {
            return [];
        }

        // Step 2 — aggregate finalized money ONLY for the bounded id set, grouped
        // by currency (never combined across currencies).
        $rows = $this->applyFilters($this->finalized(), $f)
            ->whereIn('payroll_entries.employee_id', $employeeIds)
            ->selectRaw('payroll_entries.employee_id as employee_id')
            ->selectRaw('payroll_entries.currency as currency')
            ->selectRaw('coalesce(sum(payroll_entries.gross_minor),0) as gross_minor')
            ->selectRaw('coalesce(sum(payroll_entries.deduction_minor),0) as deduction_minor')
            ->selectRaw('coalesce(sum(payroll_entries.net_minor),0) as net_minor')
            ->groupBy('payroll_entries.employee_id', 'payroll_entries.currency')
            ->get();

        // Step 3 — safe historical identity (latest finalized snapshot per employee),
        // confined to the same bounded id set. No N+1.
        $identities = $this->finalized()
            ->whereIn('payroll_entries.employee_id', $employeeIds)
            ->orderByDesc('payroll_entries.finalized_at')
            ->get(['payroll_entries.employee_id', 'payroll_entries.employee_snapshot'])
            ->groupBy('employee_id')
            ->map(fn ($g) => $g->first()->employee_snapshot ?? []);

        $byEmployee = [];
        foreach ($rows as $r) {
            $snap = $identities[$r->employee_id] ?? [];
            $byEmployee[$r->employee_id] ??= [
                'employee_id' => (string) $r->employee_id,
                'employee_number' => $snap['employee_number'] ?? null,
                'name' => $snap['name'] ?? null,
                'by_currency' => [],
            ];
            $byEmployee[$r->employee_id]['by_currency'][] = [
                'currency' => $r->currency,
                'gross_minor' => (int) $r->gross_minor,
                'deduction_minor' => (int) $r->deduction_minor,
                'net_minor' => (int) $r->net_minor,
            ];
        }

        return collect($byEmployee)
            ->sortBy(fn ($e) => $e['employee_number'] ?? '')
            ->values()
            ->all();
    }

    /**
     * Finalized line totals grouped by currency, direction, and the immutable
     * historical label_snapshot (never a mutable component label). Exposes only
     * safe fields — no source ids, metadata, internal_reason, or fingerprints.
     *
     * @param  array{payroll_period_id?:?string, employee_id?:?string, currency?:?string}  $f
     * @return array<int, array<string, mixed>>
     */
    public function components(array $f): array
    {
        return $this->applyFilters($this->finalized(), $f)
            ->join('payroll_entry_lines', 'payroll_entry_lines.payroll_entry_id', '=', 'payroll_entries.id')
            ->selectRaw('payroll_entries.currency as currency')
            ->selectRaw('payroll_entry_lines.direction as direction')
            ->selectRaw('payroll_entry_lines.line_type as line_type')
            ->selectRaw('payroll_entry_lines.label_snapshot as label')
            ->selectRaw('count(*) as line_count')
            ->selectRaw('coalesce(sum(payroll_entry_lines.amount_minor),0) as amount_minor')
            ->groupBy('payroll_entries.currency', 'payroll_entry_lines.direction', 'payroll_entry_lines.line_type', 'payroll_entry_lines.label_snapshot')
            ->orderBy('payroll_entries.currency')
            ->orderBy('payroll_entry_lines.direction')
            ->orderByDesc('amount_minor')
            ->get()
            ->map(fn ($r) => [
                'currency' => $r->currency,
                'direction' => $r->direction,
                'line_type' => $r->line_type,
                'label' => $r->label,
                'line_count' => (int) $r->line_count,
                'amount_minor' => (int) $r->amount_minor,
            ])
            ->all();
    }

    /**
     * OPERATIONAL run-status report (NOT financial). Counts runs by status with the
     * period label; carries no money and no internal calculation fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function runStatus(): array
    {
        return PayrollRun::query()
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_runs.payroll_period_id')
            ->selectRaw('payroll_runs.status as status, count(*) as run_count')
            ->groupBy('payroll_runs.status')
            ->orderBy('payroll_runs.status')
            ->get()
            ->map(fn ($r) => [
                'status' => $r->status instanceof \BackedEnum ? $r->status->value : (string) $r->status,
                'run_count' => (int) $r->run_count,
            ])
            ->all();
    }
}
