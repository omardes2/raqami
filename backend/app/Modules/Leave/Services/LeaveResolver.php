<?php

namespace App\Modules\Leave\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequestDay;
use App\Modules\Leave\Support\IntervalMath;
use App\Modules\Leave\Support\ResolvedLeaveDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The SINGLE authority Attendance consults for approved-leave coverage. Reads
 * only the frozen leave_request_days of ACTIVE leave (approved or
 * cancellation_pending — the latter stays active until final cancellation) for
 * the date, aggregating coverage across possibly multiple overlapping requests.
 * Attendance never queries leave requests ad hoc.
 */
class LeaveResolver
{
    /** Active leave states for attendance coverage (D3: cancellation_pending stays active). */
    private const ACTIVE = ['approved', 'cancellation_pending'];

    public function resolve(Employee $employee, CarbonInterface $workDate): ?ResolvedLeaveDay
    {
        $days = LeaveRequestDay::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('work_date', CarbonImmutable::parse($workDate)->toDateString())
            ->whereHas('request', fn ($q) => $q->whereIn('status', self::ACTIVE))
            ->with('request:id,leave_type_id,status')
            ->get();

        if ($days->isEmpty()) {
            return null;
        }

        $allIntervals = [];
        $contributions = [];

        foreach ($days as $day) {
            $intervals = $day->coverage_intervals ?? [];
            if ($intervals === []) {
                continue; // no attendance coverage (e.g. nominal-only consumption)
            }

            $allIntervals = array_merge($allIntervals, $intervals);
            $contributions[] = [
                'leave_request_id' => (string) $day->leave_request_id,
                'leave_type_id' => $day->request?->leave_type_id ? (string) $day->request->leave_type_id : null,
                'minutes' => IntervalMath::totalMinutes($intervals),
                'intervals' => $intervals,
            ];
        }

        if ($allIntervals === []) {
            return null;
        }

        $merged = IntervalMath::merge($allIntervals);

        return new ResolvedLeaveDay($merged, IntervalMath::totalMinutes($merged), $contributions);
    }

    /** Whether the request is in an attendance-active state. */
    public static function isActive(LeaveRequestStatus $status): bool
    {
        return in_array($status->value, self::ACTIVE, true);
    }
}
