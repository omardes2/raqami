<?php

namespace App\Modules\Payroll\Calculation\Resolvers;

use App\Modules\Attendance\Services\HolidayResolver;
use App\Modules\Attendance\Services\ScheduleResolver;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Support\IntervalMath;
use App\Modules\Payroll\Calculation\PayrollCalculationException;
use App\Modules\Payroll\Enums\PayrollErrorCode;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Resolves the AUTHORITATIVE expected scheduled working minutes for every date in
 * a payroll period, consuming the existing ScheduleResolver + HolidayResolver
 * (never reinventing schedule/holiday rules). Mirrors the attendance day
 * materializer exactly: resolve at local NOON so overnight reach-back never
 * reattributes a date to the previous day; expected work = segment intervals; a
 * holiday or non-working day contributes ZERO expected minutes.
 *
 * The returned per-date minutes/intervals form the proration denominator (summed
 * over the WHOLE month) and the basis leave/base numerators intersect against.
 */
class PayrollScheduleInputResolver
{
    public function __construct(
        private readonly ScheduleResolver $scheduleResolver,
        private readonly HolidayResolver $holidays,
    ) {}

    /**
     * @return array{days: array<string, array{minutes:int, intervals: array<int, array{start_at:string, end_at:string}>}>, period_expected_minutes: int}
     */
    public function resolve(Employee $employee, string $periodStart, string $periodEnd, string $timezone): array
    {
        try {
            $days = [];
            $total = 0;

            $cursor = CarbonImmutable::parse($periodStart, $timezone)->startOfDay();
            $last = CarbonImmutable::parse($periodEnd, $timezone)->startOfDay();

            while ($cursor->lessThanOrEqualTo($last)) {
                $dateString = $cursor->toDateString();
                $noonUtc = CarbonImmutable::parse($dateString.' 12:00:00', $timezone)->utc();
                $resolved = $this->scheduleResolver->resolveWorkDay($employee, $noonUtc, $timezone);

                $isHoliday = $this->holidays->resolve($employee->branch_id, $resolved->workDate) !== null;

                if ($isHoliday || ! $resolved->isScheduledWorkingDay()) {
                    $days[$dateString] = ['minutes' => 0, 'intervals' => []];
                    $cursor = $cursor->addDay();

                    continue;
                }

                $intervals = array_map(fn ($seg) => [
                    'start_at' => $seg->startAt->utc()->toIso8601String(),
                    'end_at' => $seg->endAt->utc()->toIso8601String(),
                ], $resolved->segments);

                $minutes = IntervalMath::totalMinutes($intervals);
                $days[$dateString] = ['minutes' => $minutes, 'intervals' => $intervals];
                $total += $minutes;

                $cursor = $cursor->addDay();
            }

            return ['days' => $days, 'period_expected_minutes' => $total];
        } catch (PayrollCalculationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PayrollCalculationException(PayrollErrorCode::ScheduleUnresolvable, ['message' => substr($e->getMessage(), 0, 120)]);
        }
    }
}
