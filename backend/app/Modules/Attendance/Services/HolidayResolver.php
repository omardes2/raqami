<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\HolidayCalendarAssignment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The single authority for "is this local date a holiday for this branch/company".
 * Calendars attach to company or branch; precedence is branch > company (most
 * specific wins). Multi-day holidays match when date <= Y <= end_date. All
 * holiday logic flows through here — never duplicated in reports/controllers.
 */
class HolidayResolver
{
    /**
     * Resolve the applicable holiday for a branch (nullable) on a local date, or
     * null when it is an ordinary day. Branch-scoped calendars win over company.
     */
    public function resolve(?string $branchId, CarbonInterface $date): ?Holiday
    {
        $day = CarbonImmutable::parse($date)->toDateString();

        // Branch first (most specific), then company.
        foreach ($this->calendarIdsByPrecedence($branchId, $day) as $calendarIds) {
            if ($calendarIds === []) {
                continue;
            }

            $holiday = Holiday::query()
                ->whereIn('holiday_calendar_id', $calendarIds)
                ->whereDate('date', '<=', $day)
                ->where(function ($q) use ($day) {
                    $q->whereNull('end_date')->whereDate('date', $day)
                        ->orWhereDate('end_date', '>=', $day);
                })
                ->orderBy('date')
                ->first();

            if ($holiday !== null) {
                return $holiday;
            }
        }

        return null;
    }

    public function isHoliday(?string $branchId, CarbonInterface $date): bool
    {
        return $this->resolve($branchId, $date) !== null;
    }

    /**
     * Calendar-id groups ordered by precedence (branch group first, then company),
     * limited to assignments effective on $day.
     *
     * @return array<int, array<int, string>>
     */
    private function calendarIdsByPrecedence(?string $branchId, string $day): array
    {
        $effective = HolidayCalendarAssignment::query()
            ->whereDate('effective_from', '<=', $day)
            ->where(function ($q) use ($day) {
                $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day);
            })
            ->get();

        $branch = $branchId === null ? [] : $effective
            ->where('scope_type', 'branch')
            ->where('scope_id', $branchId)
            ->pluck('holiday_calendar_id')->unique()->values()->all();

        $company = $effective
            ->where('scope_type', 'company')
            ->pluck('holiday_calendar_id')->unique()->values()->all();

        return [$branch, $company];
    }
}
