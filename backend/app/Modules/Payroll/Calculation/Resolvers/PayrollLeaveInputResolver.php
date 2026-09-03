<?php

namespace App\Modules\Payroll\Calculation\Resolvers;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveResolver;
use App\Modules\Leave\Support\IntervalMath;
use Carbon\CarbonImmutable;

/**
 * Payroll's dedicated Leave input adapter. Consumes the authoritative LeaveResolver
 * (active = approved + cancellation_pending) and uses COVERAGE minutes intersected
 * with the day's expected work — never balance consumption_minutes. Classifies each
 * covering request via leave_types.paid_classification (paid|unpaid|null). Coverage
 * on the same date is claimed once (no double counting across overlapping requests).
 */
class PayrollLeaveInputResolver
{
    public function __construct(private readonly LeaveResolver $leaveResolver) {}

    /**
     * @param  array<string, array<int, array{start_at:string, end_at:string}>>  $expectedIntervalsByDate
     * @return array<int, array{work_date:string, leave_request_id:string, leave_type_id:?string, classification:?string, coverage_minutes:int}>
     */
    public function resolve(Employee $employee, array $expectedIntervalsByDate): array
    {
        $classMap = LeaveType::query()->pluck('paid_classification', 'id')->all();
        $records = [];

        foreach ($expectedIntervalsByDate as $date => $expected) {
            if ($expected === []) {
                continue; // no expected work → no payable leave impact
            }

            $resolved = $this->leaveResolver->resolve($employee, CarbonImmutable::parse($date));
            if ($resolved === null) {
                continue;
            }

            // Deterministic order so claimed-subtraction is stable.
            $contributions = $resolved->contributions;
            usort($contributions, fn ($a, $b) => [$a['leave_request_id'], $a['leave_type_id'] ?? ''] <=> [$b['leave_request_id'], $b['leave_type_id'] ?? '']);

            $claimed = [];
            foreach ($contributions as $c) {
                $available = $claimed === [] ? $expected : IntervalMath::subtract($expected, $claimed);
                $within = IntervalMath::subtract($available, IntervalMath::subtract($available, $c['intervals']));
                $minutes = IntervalMath::totalMinutes($within);
                if ($within !== []) {
                    $claimed = IntervalMath::merge(array_merge($claimed, $within));
                }

                $leaveTypeId = $c['leave_type_id'];
                $records[] = [
                    'work_date' => $date,
                    'leave_request_id' => $c['leave_request_id'],
                    'leave_type_id' => $leaveTypeId,
                    'classification' => $leaveTypeId !== null ? ($classMap[$leaveTypeId] ?? null) : null,
                    'coverage_minutes' => $minutes,
                ];
            }
        }

        return $records;
    }
}
