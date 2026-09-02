<?php

namespace App\Console\Commands;

use App\Modules\Attendance\Services\AttendanceDailyProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Thin entry point for daily attendance materialization (weekend/holiday/absent/
 * incomplete). Idempotent and safe to run repeatedly; a scheduler can invoke it
 * every few minutes. Domain logic lives in the module services, not here.
 *
 * Usage:
 *   attendance:process-daily            # materialize today (UTC)
 *   attendance:process-daily --date=... # materialize a specific YYYY-MM-DD
 */
class ProcessDailyAttendance extends Command
{
    protected $signature = 'attendance:process-daily {--date= : Local date to materialize (YYYY-MM-DD); defaults to today}';

    protected $description = 'Materialize daily attendance state (weekend, holiday, absent, incomplete) across all tenants.';

    public function handle(AttendanceDailyProcessor $processor): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $result = $processor->process($date);

        $this->info(sprintf(
            'Attendance materialized for %s: tenants=%d absent=%d weekend=%d holiday=%d on_leave=%d incomplete=%d skipped=%d anomalies=%d errors=%d',
            $date->toDateString(),
            $result['tenants'], $result['absent'], $result['weekend'],
            $result['holiday'], $result['on_leave'], $result['incomplete'], $result['skipped'], $result['anomalies'], $result['errors'],
        ));

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
