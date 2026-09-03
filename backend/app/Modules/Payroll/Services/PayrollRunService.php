<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\PayrollLock;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payroll run lifecycle (Phase-1 skeleton). Exactly one non-cancelled run per
 * period (advisory lock + partial-unique backstop). Calculation and finalization
 * arrive in later phases; Phase 1 supports create and cancel (so a cancelled
 * draft can be replaced while the period stays open).
 */
class PayrollRunService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function create(User $actor, PayrollPeriod $period): PayrollRun
    {
        if ($period->status !== PayrollPeriodStatus::Open) {
            throw ValidationException::withMessages(['period' => [__('payroll.period_closed')]]);
        }

        return DB::transaction(function () use ($actor, $period) {
            PayrollLock::forPeriodRun((string) $this->context->tenantId(), (string) $period->getKey());

            $active = PayrollRun::query()
                ->where('payroll_period_id', $period->getKey())
                ->where('status', '!=', PayrollRunStatus::Cancelled->value)
                ->exists();
            if ($active) {
                throw ValidationException::withMessages(['run' => [__('payroll.run_exists')]]);
            }

            $run = PayrollRun::query()->create([
                'payroll_period_id' => $period->getKey(),
                'status' => PayrollRunStatus::Draft,
                'created_by_user_id' => (string) $actor->getKey(),
                'version' => 1,
            ]);

            $this->audit->log('payroll.run_created', [
                'actor' => $actor, 'subject' => $run,
                'metadata' => ['payroll_period_id' => $period->getKey(), 'status' => $run->status->value],
            ]);

            return $run->fresh();
        });
    }

    /** Cancel a pre-finalization run so the period can receive a replacement run. */
    public function cancel(User $actor, PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($actor, $run) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->getKey());
            if (! $run->status->canTransitionTo(PayrollRunStatus::Cancelled)) {
                throw ValidationException::withMessages(['status' => [__('payroll.run_not_cancellable')]]);
            }

            $run->forceFill([
                'status' => PayrollRunStatus::Cancelled,
                'cancelled_by_user_id' => (string) $actor->getKey(),
                'cancelled_at' => Carbon::now()->utc(),
                'version' => (int) $run->version + 1,
            ])->save();

            $this->audit->log('payroll.run_cancelled', [
                'actor' => $actor, 'subject' => $run,
                'metadata' => ['payroll_period_id' => $run->payroll_period_id],
            ]);

            return $run->fresh();
        });
    }
}
