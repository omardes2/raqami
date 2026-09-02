<?php

namespace App\Console\Commands;

use App\Modules\Leave\Services\LeaveProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduler-ready entry point for leave period transitions: carry-forward and
 * expiry for ended entitlement periods. Idempotent; no cron is configured here.
 *
 *   leave:process-periods              # close periods ended before today
 *   leave:process-periods --date=...   # as of a specific YYYY-MM-DD
 */
class ProcessLeavePeriods extends Command
{
    protected $signature = 'leave:process-periods {--date= : Date to process period closures as of (YYYY-MM-DD); defaults to today}';

    protected $description = 'Carry forward and expire ended leave entitlement periods across all tenants (idempotent).';

    public function handle(LeaveProcessor $processor): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $result = $processor->processPeriods($date);

        $this->info(sprintf(
            'Leave period closures for %s: tenants=%d closed=%d carried=%d expired=%d errors=%d',
            $date->toDateString(),
            $result['tenants'], $result['closed'], $result['carried'], $result['expired'], $result['errors'],
        ));

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
