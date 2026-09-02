<?php

namespace App\Console\Commands;

use App\Modules\Leave\Services\LeaveProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduler-ready entry point for leave accrual (upfront grants + monthly/annual
 * accrual). Idempotent and safe to run repeatedly; no cron is configured here.
 *
 *   leave:process-accruals              # accrue as of today
 *   leave:process-accruals --date=...   # accrue as of a specific YYYY-MM-DD
 */
class ProcessLeaveAccruals extends Command
{
    protected $signature = 'leave:process-accruals {--date= : Date to accrue as of (YYYY-MM-DD); defaults to today}';

    protected $description = 'Apply leave grants and accruals across all tenants (idempotent).';

    public function handle(LeaveProcessor $processor): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $result = $processor->processAccruals($date);

        $this->info(sprintf(
            'Leave accruals for %s: tenants=%d granted=%d accrued=%d errors=%d',
            $date->toDateString(),
            $result['tenants'], $result['granted'], $result['accrued'], $result['errors'],
        ));

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
