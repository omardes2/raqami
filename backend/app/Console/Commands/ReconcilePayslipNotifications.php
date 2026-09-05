<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Services\NotificationMaintenanceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Sprint 8B — recover payslip notifications missed because the broker was down or
 * the fan-out job failed. Re-dispatches the SAME idempotent job for finalized
 * runs in a bounded recent window; the dedupe key prevents duplicates and
 * payroll is never modified. Safe to re-run. External cron model.
 *
 *   notifications:reconcile-payslips              # last 35 days
 *   notifications:reconcile-payslips --days=90    # custom window
 */
class ReconcilePayslipNotifications extends Command
{
    protected $signature = 'notifications:reconcile-payslips {--days=35 : How many days back to scan finalized runs}';

    protected $description = 'Re-deliver missing payslip notifications for recently finalized payroll runs (idempotent).';

    public function handle(NotificationMaintenanceService $maintenance): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = CarbonImmutable::now()->utc()->subDays($days);

        $result = $maintenance->reconcilePayslips($since);

        $this->info(sprintf(
            'Payslip reconciliation (since %s): tenants=%d runs=%d errors=%d',
            $since->toDateString(),
            $result['tenants'], $result['runs'], $result['errors'],
        ));

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
