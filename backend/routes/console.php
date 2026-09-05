<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled maintenance (production)
|--------------------------------------------------------------------------
| A single system cron entry runs `php artisan schedule:run` every minute
| (see docs/DEPLOYMENT.md); Laravel fires the tasks below at their times. Every
| command is idempotent and tenant-aware (it iterates all tenants under each
| tenant's own context), so a retried or overlapping run cannot corrupt state.
| onOneServer keeps a task single-fired across a multi-node deployment; times
| are UTC and staggered to avoid resource spikes.
*/

// Attendance: materialize daily state (weekend/holiday/absent/incomplete).
// Idempotent — safe to re-run; operators with many timezones may raise cadence.
Schedule::command('attendance:process-daily')
    ->dailyAt('01:00')->withoutOverlapping()->onOneServer();

// Leave: apply grants/accruals, then carry-forward/expire ended periods.
Schedule::command('leave:process-accruals')
    ->dailyAt('01:15')->withoutOverlapping()->onOneServer();
Schedule::command('leave:process-periods')
    ->dailyAt('01:30')->withoutOverlapping()->onOneServer();

// Billing: trial/grace expiry, scheduled cancellation/downgrade transitions.
Schedule::command('billing:process-lifecycle')
    ->dailyAt('02:00')->withoutOverlapping()->onOneServer();

// Notifications: re-deliver any missed payslip notifications (safety net),
// remind assignees of due-soon/overdue tasks, and prune old rows weekly.
Schedule::command('notifications:reconcile-payslips')
    ->dailyAt('02:30')->withoutOverlapping()->onOneServer();
Schedule::command('notifications:remind-tasks')
    ->dailyAt('06:00')->withoutOverlapping()->onOneServer();
Schedule::command('notifications:prune')
    ->weeklyOn(0, '03:00')->withoutOverlapping()->onOneServer();

// Queue hygiene: drop stale failed jobs and batch records.
Schedule::command('queue:prune-failed --hours=168')
    ->weeklyOn(0, '03:30')->onOneServer();
Schedule::command('queue:prune-batches')
    ->weeklyOn(0, '03:45')->onOneServer();
