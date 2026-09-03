<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual payroll adjustments (Phase 2B), owned by (period, employee). An adjustment
 * is an authoritative calculation INPUT for the whole period: a replacement run for
 * the same period automatically consumes the same rows. Adding, editing, or removing
 * one makes an already-calculated entry stale, so the run must be recalculated before
 * it can be approved/finalized. Mutation is allowed only while the period is open and
 * its active (non-cancelled) run, if any, is pre-approval; a cancelled run never
 * freezes the period's adjustments, and the closed-period DB trigger is the backstop.
 * Locks in period -> run order (matching approval/finalization). Self-payroll is
 * blocked unless the tenant allows it; company-level authority is enforced upstream.
 */
class PayrollAdjustmentService
{
    /** Active-run states in which a period's adjustments may be created/edited/removed. */
    private const MUTABLE_RUN_STATES = [
        PayrollRunStatus::Draft,
        PayrollRunStatus::Calculated,
        PayrollRunStatus::CalculationFailed,
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    /**
     * @param  array{employee_visible_label:string, direction:string, amount_minor:int, currency:string, internal_reason:string, source_payroll_entry_id?:string|null}  $data
     */
    public function create(User $actor, PayrollPeriod $period, string $employeeId, array $data): PayrollAdjustment
    {
        $this->authz->assertNotSelfManagement($actor, $employeeId);

        return DB::transaction(function () use ($actor, $period, $employeeId, $data) {
            $period = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->getKey());
            $this->assertPeriodMutable($period);
            $this->assertEmployeeInPeriod($period, $employeeId);

            $source = $data['source_payroll_entry_id'] ?? null;
            if ($source !== null) {
                $this->assertValidSource((string) $source, $employeeId, $period);
            }

            $adjustment = PayrollAdjustment::query()->create([
                'payroll_period_id' => (string) $period->getKey(),
                'employee_id' => $employeeId,
                'employee_visible_label' => $data['employee_visible_label'],
                'direction' => $data['direction'],
                'amount_minor' => $data['amount_minor'],
                'currency' => strtoupper($data['currency']),
                'internal_reason' => $data['internal_reason'],
                'source_payroll_entry_id' => $source,
                'created_by_user_id' => (string) $actor->getKey(),
                'version' => 1,
            ]);

            $this->auditAction('payroll.adjustment_created', $actor, $adjustment);

            return $adjustment->fresh();
        });
    }

    /**
     * @param  array{employee_visible_label?:string, direction?:string, amount_minor?:int, currency?:string, internal_reason?:string, source_payroll_entry_id?:string|null}  $data
     */
    public function update(User $actor, PayrollAdjustment $adjustment, array $data): PayrollAdjustment
    {
        $this->authz->assertNotSelfManagement($actor, (string) $adjustment->employee_id);

        return DB::transaction(function () use ($actor, $adjustment, $data) {
            $adjustment = PayrollAdjustment::query()->lockForUpdate()->findOrFail($adjustment->getKey());
            $period = PayrollPeriod::query()->lockForUpdate()->findOrFail($adjustment->payroll_period_id);
            $this->assertPeriodMutable($period);

            if (array_key_exists('source_payroll_entry_id', $data) && $data['source_payroll_entry_id'] !== null) {
                $this->assertValidSource((string) $data['source_payroll_entry_id'], (string) $adjustment->employee_id, $period);
            }

            foreach (['employee_visible_label', 'direction', 'amount_minor', 'internal_reason', 'source_payroll_entry_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $adjustment->{$field} = $data[$field];
                }
            }
            if (array_key_exists('currency', $data)) {
                $adjustment->currency = strtoupper((string) $data['currency']);
            }
            $adjustment->version = (int) $adjustment->version + 1;
            $adjustment->save();

            $this->auditAction('payroll.adjustment_updated', $actor, $adjustment);

            return $adjustment->fresh();
        });
    }

    public function delete(User $actor, PayrollAdjustment $adjustment): void
    {
        $this->authz->assertNotSelfManagement($actor, (string) $adjustment->employee_id);

        DB::transaction(function () use ($actor, $adjustment) {
            $adjustment = PayrollAdjustment::query()->lockForUpdate()->findOrFail($adjustment->getKey());
            $period = PayrollPeriod::query()->lockForUpdate()->findOrFail($adjustment->payroll_period_id);
            $this->assertPeriodMutable($period);

            $snapshot = clone $adjustment;
            $adjustment->delete();

            $this->auditAction('payroll.adjustment_deleted', $actor, $snapshot);
        });
    }

    /**
     * The period must be open, and its active (non-cancelled) run — if one exists —
     * must be pre-approval. Assumes the period is already locked FOR UPDATE; locks the
     * active run FOR UPDATE next (period -> run order).
     */
    private function assertPeriodMutable(PayrollPeriod $period): void
    {
        if ($period->status === PayrollPeriodStatus::Closed) {
            throw ValidationException::withMessages(['period' => [__('payroll.period_closed')]]);
        }

        $activeRun = PayrollRun::query()
            ->where('payroll_period_id', $period->getKey())
            ->where('status', '!=', PayrollRunStatus::Cancelled->value)
            ->lockForUpdate()
            ->first();

        if ($activeRun !== null && ! in_array($activeRun->status, self::MUTABLE_RUN_STATES, true)) {
            throw ValidationException::withMessages(['run' => [__('payroll.adjustment_run_locked')]]);
        }
    }

    /** The target employee's employment interval must overlap the target period. */
    private function assertEmployeeInPeriod(PayrollPeriod $period, string $employeeId): void
    {
        $employee = Employee::withTrashed()->find($employeeId);
        if ($employee === null) {
            throw ValidationException::withMessages(['employee' => [__('payroll.adjustment_employee_not_in_period')]]);
        }

        $periodStart = $period->period_start->toDateString();
        $periodEnd = $period->period_end->toDateString();
        $hire = $employee->hire_date?->toDateString();
        $term = $employee->termination_date?->toDateString();

        $overlaps = ($hire === null || $hire <= $periodEnd) && ($term === null || $term >= $periodStart);
        if (! $overlaps) {
            throw ValidationException::withMessages(['employee' => [__('payroll.adjustment_employee_not_in_period')]]);
        }
    }

    /**
     * Traceability only: the source entry must be the same employee's FINALIZED entry
     * from a strictly-earlier period. No amount is inferred from it.
     */
    private function assertValidSource(string $sourceEntryId, string $employeeId, PayrollPeriod $targetPeriod): void
    {
        $entry = PayrollEntry::query()->with('run.period')->find($sourceEntryId);
        $sourcePeriod = $entry?->run?->period;

        if ($entry === null
            || (string) $entry->employee_id !== $employeeId
            || $entry->status !== PayrollEntryStatus::Finalized
            || $sourcePeriod === null
            || $sourcePeriod->period_end->toDateString() >= $targetPeriod->period_start->toDateString()) {
            throw ValidationException::withMessages(['source_payroll_entry_id' => [__('payroll.adjustment_invalid_source')]]);
        }
    }

    /** Audit carries ids/direction/currency/action only — never amount or reason. */
    private function auditAction(string $action, User $actor, PayrollAdjustment $adjustment): void
    {
        $this->audit->log($action, [
            'actor' => $actor, 'subject' => $adjustment,
            'metadata' => [
                'payroll_period_id' => (string) $adjustment->payroll_period_id,
                'employee_id' => (string) $adjustment->employee_id,
                'direction' => (string) $adjustment->direction,
                'currency' => (string) $adjustment->currency,
            ],
        ]);
    }
}
