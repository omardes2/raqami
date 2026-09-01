<?php

namespace App\Modules\Attendance\Support;

use Carbon\CarbonImmutable;

/**
 * One expected work segment on a work_date, boundaries already resolved to UTC.
 * The building block of split shifts — a day may have several. Immutable.
 */
final class ScheduledSegment
{
    public function __construct(
        public readonly int $sequence,
        public readonly CarbonImmutable $startAt,      // UTC
        public readonly CarbonImmutable $endAt,        // UTC (next day if overnight)
        public readonly int $graceMinutes,
        public readonly int $breakMinutes,
        public readonly int $overtimeAfterMinutes,
    ) {}
}
