<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Jobs\PayslipNotificationJob;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sprint 8B — notification maintenance run across every tenant, each inside its
 * own tenant context so RLS holds and one tenant's failure never stops the rest.
 *
 * prune(): hard-deletes notifications older than the retention horizon (12
 * months) under a TRANSACTION-LOCAL maintenance context bounded by an explicit
 * cutoff GUC (app.notification_prune_before). The RLS policies restrict the
 * maintenance context to rows strictly older than that cutoff, so prune can
 * never read or delete recent rows, another tenant's rows, or (without the
 * cutoff) anything at all. Deletes in bounded batches.
 *
 * reconcilePayslips(): recovers payslip notifications missed because the broker
 * was down or the fan-out job failed, by re-dispatching the SAME idempotent job
 * for finalized runs in a bounded recent window. The dedupe key guarantees no
 * duplicates; payroll is never modified.
 */
class NotificationMaintenanceService
{
    /** Rows deleted per batch (bounded so no single statement is unbounded). */
    private const PRUNE_BATCH = 1000;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{tenants:int, deleted:int, errors:int}
     */
    public function prune(CarbonImmutable $olderThan): array
    {
        $cutoff = $olderThan->toIso8601String();
        $totals = ['tenants' => 0, 'deleted' => 0, 'errors' => 0];

        foreach ($this->tenantIds() as $tenantId) {
            $totals['tenants']++;
            try {
                $totals['deleted'] += $this->context->runAs($tenantId, function () use ($cutoff) {
                    $deleted = 0;
                    do {
                        $batch = DB::transaction(function () use ($cutoff) {
                            // Transaction-local maintenance context + explicit cutoff;
                            // both discarded on COMMIT/ROLLBACK. The RLS policies bound
                            // visibility/deletion to rows older than the cutoff.
                            DB::statement("select set_config('app.notification_maintenance', '1', true)");
                            DB::statement("select set_config('app.notification_prune_before', ?, true)", [$cutoff]);

                            // The maintenance SELECT policy already restricts these ids
                            // to rows older than the cutoff; delete exactly them.
                            $ids = DB::table('notifications')->limit(self::PRUNE_BATCH)->pluck('id')->all();
                            if ($ids === []) {
                                return 0;
                            }

                            return DB::table('notifications')->whereIn('id', $ids)->delete();
                        });
                        $deleted += (int) $batch;
                    } while ($batch > 0);

                    return $deleted;
                });
            } catch (Throwable $e) {
                $totals['errors']++;
                report($e);
            }
        }

        return $totals;
    }

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
