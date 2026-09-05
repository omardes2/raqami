<?php

namespace App\Modules\Payroll\Jobs;

use App\Modules\Employees\Models\Employee;
use App\Modules\Notifications\Services\NotificationPayloadFactory;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollRun;
use App\Support\Queue\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sprint 8B — ONE TenantAware fan-out job per finalized payroll run. Dispatched
 * post-commit from finalization (never a per-entry synchronous insert in the
 * finalize request). It receives only the run id and re-queries authoritative
 * finalized state under the restored tenant context: it acts ONLY when the run
 * is Finalized and its period Closed, iterates finalized entries in chunks, maps
 * each entry's employee to a User, and sends "payslip available" — never any
 * money. The dedupe key (payroll.payslip_available:{entryId}) makes retries and
 * the reconcile command idempotent. Per-recipient failures are logged, not fatal.
 */
class PayslipNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAware;

    public int $tries = 3;

    public function __construct(public readonly string $payrollRunId)
    {
        $this->captureTenantContext();
    }

    public function handle(NotificationService $notifications): void
    {
        $run = PayrollRun::query()->find($this->payrollRunId);
        if ($run === null || $run->status !== PayrollRunStatus::Finalized) {
            return;
        }

        $period = $run->period;
        if ($period === null || $period->status !== PayrollPeriodStatus::Closed) {
            return;
        }
        $periodLabel = (string) ($period->label ?? '');

        PayrollEntry::query()
            ->where('payroll_run_id', $this->payrollRunId)
            ->where('status', PayrollEntryStatus::Finalized->value)
            ->orderBy('id')
            ->chunkById(200, function ($entries) use ($notifications, $periodLabel) {
                foreach ($entries as $entry) {
                    $userId = Employee::query()->whereKey($entry->employee_id)->value('user_id');
                    if ($userId === null) {
                        continue; // Employee without a linked User → nothing to deliver.
                    }
                    try {
                        $notifications->send(
                            (string) $userId,
                            NotificationPayloadFactory::payrollPayslipAvailable((string) $entry->getKey(), $periodLabel),
                        );
                    } catch (Throwable $e) {
                        // One recipient's failure must not abort the fan-out; the dedupe
                        // key lets a retry/reconcile pick it up later.
                        Log::warning('notification.delivery_failed', [
                            'domain' => 'payroll',
                            'event' => 'payroll.payslip_available',
                            'payroll_entry_id' => (string) $entry->getKey(),
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
