<?php

namespace App\Modules\Leave\Support;

use App\Modules\Leave\Enums\ConsumptionBasis;
use App\Modules\Leave\Enums\LeaveRequestKind;

/**
 * The computed effect of a leave request on ONE logical work_date. Separates
 * balance CONSUMPTION from attendance COVERAGE (Correction A/B, D7). Immutable;
 * frozen into a leave_request_days row at submission.
 */
final class LeaveDayComputation
{
    /**
     * @param  array<int, array{start_at:string,end_at:string}>  $coverageIntervals
     * @param  array<string, mixed>|null  $holidaySnapshot
     * @param  array<string, mixed>|null  $scheduleSnapshot
     */
    public function __construct(
        public readonly string $workDate,
        public readonly string $timezone,
        public readonly int $scheduledMinutes,
        public readonly int $coverageMinutes,
        public readonly int $consumptionMinutes,
        public readonly LeaveRequestKind $portion,
        public readonly array $coverageIntervals,
        public readonly ConsumptionBasis $consumptionBasis,
        public readonly ?int $nominalDayMinutes,
        public readonly ?string $excludedReason,
        public readonly ?string $holidayId,
        public readonly ?array $holidaySnapshot,
        public readonly ?array $scheduleSnapshot,
    ) {}

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'work_date' => $this->workDate,
            'timezone' => $this->timezone,
            'scheduled_minutes' => $this->scheduledMinutes,
            'coverage_minutes' => $this->coverageMinutes,
            'consumption_minutes' => $this->consumptionMinutes,
            'portion' => $this->portion,
            'coverage_intervals' => $this->coverageIntervals,
            'consumption_basis' => $this->consumptionBasis,
            'nominal_day_minutes' => $this->nominalDayMinutes,
            'excluded_reason' => $this->excludedReason,
            'holiday_id' => $this->holidayId,
            'holiday_snapshot' => $this->holidaySnapshot,
            'schedule_snapshot' => $this->scheduleSnapshot,
        ];
    }
}
