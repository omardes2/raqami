<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Services\NotificationMaintenanceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Sprint 8B — hard-delete notifications past the 12-month retention horizon
 * across all tenants (idempotent, per-tenant isolated, bounded batches). Runs
 * under a cutoff-bounded maintenance context so it can only ever touch rows
 * older than the horizon. Scheduler-ready; external cron model.
 *
 *   notifications:prune                 # delete older than 12 months
 *   notifications:prune --months=18     # custom horizon
 */
class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune {--months=12 : Retention horizon in months (older rows are hard-deleted)}';

    protected $description = 'Hard-delete notifications older than the retention horizon across all tenants.';

    public function handle(NotificationMaintenanceService $maintenance): int
    {
        $months = max(1, (int) $this->option('months'));
        $olderThan = CarbonImmutable::now()->utc()->subMonths($months);

        $result = $maintenance->prune($olderThan);

        $this->info(sprintf(
            'Notification prune (< %s): tenants=%d deleted=%d errors=%d',
            $olderThan->toDateString(),
            $result['tenants'], $result['deleted'], $result['errors'],
        ));

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
