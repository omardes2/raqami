<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Jobs\PayslipNotificationJob;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Sprint 8B — notification maintenance run across every tenant, each inside its
 * own tenant context so RLS holds and one tenant's failure never stops the rest.
 *
 * reconcilePayslips(): recovers payslip notifications missed because the broker
 * was down or the fan-out job failed, by re-dispatching the SAME idempotent job
 * for finalized runs in a bounded recent window. The dedupe key guarantees no
 * duplicates; payroll is never modified.
 */
class NotificationMaintenanceService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{tenants:int, runs:int, errors:int}
     */
    public function reconcilePayslips(CarbonImmutable $since): array
    {
        $totals = ['tenants' => 0, 'runs' => 0, 'errors' => 0];

        foreach ($this->tenantIds() as $tenantId) {
            $totals['tenants']++;
            try {
                $this->context->runAs($tenantId, function () use ($since, &$totals) {
                    $runIds = PayrollRun::query()
                        ->where('status', PayrollRunStatus::Finalized->value)
                        ->where('finalized_at', '>=', $since)
                        ->whereHas('period', fn ($q) => $q->where('status', PayrollPeriodStatus::Closed->value))
                        ->orderBy('id')
                        ->pluck('id');

                    foreach ($runIds as $runId) {
                        $totals['runs']++;
                        // Same idempotent job; the dedupe key prevents duplicates.
                        PayslipNotificationJob::dispatchSync((string) $runId);
                    }
                });
            } catch (Throwable $e) {
                $totals['errors']++;
                report($e);
            }
        }

        return $totals;
    }

    /** @return array<int, string> */
    private function tenantIds(): array
    {
        return $this->context->runAsPlatform(
            fn () => Tenant::query()->orderBy('id')->pluck('id')->all()
        );
    }
}
