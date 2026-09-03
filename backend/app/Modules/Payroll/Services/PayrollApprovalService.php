<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use App\Modules\Payroll\Support\PayrollLock;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payroll run approval (Phase 2B): a calculated run is approved before finalization.
 * When the tenant requires four-eyes, the approver MUST differ from whoever
 * requested the calculation (separation of duties), and finalization then requires
 * this explicit approval. Approval also gates on run readiness — a stale, incomplete,
 * or cohort-drifted run cannot be approved until it is recalculated. Self-payroll is
 * blocked unless the tenant allows it. Company-level authority is enforced upstream.
 */
class PayrollApprovalService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly PayrollSettingsService $settings,
        private readonly PayrollRunReadinessService $readiness,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    public function approve(User $actor, PayrollRun $run): PayrollRun
    {
        $this->authz->assertNotSelfPayrollRun($actor, (string) $run->getKey());
        $settings = $this->settings->getOrCreate();

        $run = DB::transaction(function () use ($actor, $run, $settings) {
            PayrollLock::forPeriodRun((string) $this->context->tenantId(), (string) $run->payroll_period_id);
            $run = PayrollRun::query()->with('period')->lockForUpdate()->findOrFail($run->getKey());

            if ($run->status !== PayrollRunStatus::Calculated) {
                throw ValidationException::withMessages(['run' => [__('payroll.run_not_approvable')]]);
            }

            // Four-eyes: the approver may not be the calculation requester.
            if ($settings->require_four_eyes
                && (string) $run->calculation_requested_by_user_id === (string) $actor->getKey()) {
                throw ValidationException::withMessages(['run' => [__('payroll.four_eyes_approver')]]);
            }

            $entries = PayrollEntry::query()->where('payroll_run_id', $run->getKey())->get();
            $this->readiness->assertAllCalculated($entries);
            $this->readiness->assertCohortCurrent($run, $entries);
            $this->readiness->assertNoneStale($entries);

            $run->forceFill([
                'status' => PayrollRunStatus::Approved,
                'approved_by_user_id' => (string) $actor->getKey(),
                'approved_at' => CarbonImmutable::now()->utc(),
                'version' => (int) $run->version + 1,
            ])->save();

            return $run->fresh();
        });

        $this->audit->log('payroll.run_approved', [
            'actor' => $actor, 'subject' => $run,
            'metadata' => ['payroll_period_id' => $run->payroll_period_id],
        ]);

        return $run;
    }
}
