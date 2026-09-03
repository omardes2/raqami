<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual payroll adjustments (Phase 2B). An adjustment is an authoritative
 * calculation INPUT keyed by (run, employee): adding or removing one makes the
 * employee's entry stale, so the run must be recalculated before it can be
 * approved/finalized. Adjustments are only mutable while the run is pre-approval
 * (draft/calculated/calculation_failed) and its period is open; the closed-period
 * DB trigger is the final backstop. Self-payroll is blocked unless the tenant
 * allows it. Company-level payroll authority is enforced by the caller.
 */
class PayrollAdjustmentService
{
    /** Run states in which adjustments may be created or removed. */
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
     * @param  array{label:string, direction:string, amount_minor:int, currency:string, reason:string}  $data
     */
    public function create(User $actor, PayrollRun $run, string $employeeId, array $data): PayrollAdjustment
    {
        $this->authz->assertNotSelfManagement($actor, $employeeId);

        return DB::transaction(function () use ($actor, $run, $employeeId, $data) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $this->assertRunMutable($run);

            // The employee must exist in this tenant (soft-deleted historical rows
            // are valid payroll subjects; RLS already scopes to the tenant).
            Employee::withTrashed()->findOrFail($employeeId);

            $adjustment = PayrollAdjustment::query()->create([
                'payroll_run_id' => (string) $run->getKey(),
                'employee_id' => $employeeId,
                'label' => $data['label'],
                'direction' => $data['direction'],
                'amount_minor' => $data['amount_minor'],
                'currency' => strtoupper($data['currency']),
                'reason' => $data['reason'],
                'created_by_user_id' => (string) $actor->getKey(),
                'version' => 1,
            ]);

            $this->audit->log('payroll.adjustment_created', [
                'actor' => $actor, 'subject' => $adjustment,
                'metadata' => [
                    'payroll_run_id' => (string) $run->getKey(),
                    'employee_id' => $employeeId,
                    'direction' => $data['direction'],
                    'currency' => strtoupper($data['currency']),
                ],
            ]);

            return $adjustment->fresh();
        });
    }

    public function delete(User $actor, PayrollAdjustment $adjustment): void
    {
        $this->authz->assertNotSelfManagement($actor, (string) $adjustment->employee_id);

        DB::transaction(function () use ($actor, $adjustment) {
            $adjustment = PayrollAdjustment::query()->lockForUpdate()->findOrFail($adjustment->getKey());
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($adjustment->payroll_run_id);
            $this->assertRunMutable($run);

            $meta = [
                'payroll_run_id' => (string) $adjustment->payroll_run_id,
                'employee_id' => (string) $adjustment->employee_id,
            ];
            $adjustment->delete();

            $this->audit->log('payroll.adjustment_deleted', [
                'actor' => $actor, 'subject' => $adjustment, 'metadata' => $meta,
            ]);
        });
    }

    /** A run accepts adjustment changes only while pre-approval and its period open. */
    private function assertRunMutable(PayrollRun $run): void
    {
        if (! in_array($run->status, self::MUTABLE_RUN_STATES, true)) {
            throw ValidationException::withMessages(['run' => [__('payroll.adjustment_run_locked')]]);
        }

        $run->loadMissing('period');
        if ($run->period?->status === PayrollPeriodStatus::Closed) {
            throw ValidationException::withMessages(['period' => [__('payroll.period_closed')]]);
        }
    }
}
