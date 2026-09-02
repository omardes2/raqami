<?php

namespace App\Modules\Leave\Services;

use App\Modules\Attendance\Support\AttendanceEligibility;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\AccrualFrequency;
use App\Modules\Leave\Enums\EntitlementMethod;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveEntitlementPeriod;
use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generic, idempotent accrual for the ACTIVE tenant. Applies upfront/fixed grants
 * and monthly/annual accrual per the resolved policy, capped by max_balance. Each
 * ledger row carries a stable idempotency key so re-runs never duplicate. Ledger-
 * based, timezone-safe (date-only anchors), no pay-period accrual (Payroll,
 * Sprint 7), no cron (a scheduler/command invokes it).
 */
class LeaveAccrualService
{
    public function __construct(
        private readonly LeavePolicyResolver $policyResolver,
        private readonly LeaveEntitlementPeriodService $periods,
        private readonly LeaveBalanceService $balances,
    ) {}

    /** @return array{granted:int, accrued:int} */
    public function processForDate(CarbonImmutable $date): array
    {
        $counts = ['granted' => 0, 'accrued' => 0];

        $types = LeaveType::query()->where('status', 'active')->get();
        if ($types->isEmpty()) {
            return $counts;
        }

        Employee::query()
            ->whereIn('employment_status', AttendanceEligibility::ELIGIBLE_STATUSES)
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($types, $date, &$counts) {
                foreach ($employees as $employee) {
                    foreach ($types as $type) {
                        $policy = $this->policyResolver->resolve($employee, $type->getKey(), $date);
                        if ($policy === null || $policy->entitlement_method === EntitlementMethod::None) {
                            continue;
                        }
                        $result = $this->applyForEmployee($employee, $type->getKey(), $policy, $date);
                        $counts['granted'] += $result['granted'];
                        $counts['accrued'] += $result['accrued'];
                    }
                }
            });

        return $counts;
    }

    /** @return array{granted:int, accrued:int} */
    private function applyForEmployee(Employee $employee, string $leaveTypeId, LeavePolicy $policy, CarbonImmutable $date): array
    {
        $period = $this->periods->resolveOrCreate($employee, $leaveTypeId, $policy, $date);

        return DB::transaction(fn () => $this->balances->withLockedBalance($period, function (LeaveBalance $balance) use ($policy, $period, $date) {
            $granted = 0;
            $accrued = 0;

            if ($policy->entitlement_method === EntitlementMethod::Fixed) {
                $granted += $this->grantOnce($balance, $policy, $period, (int) $policy->entitlement_minutes, 'grant:'.$period->getKey(), $date, 'grant');
            }

            if ($policy->entitlement_method === EntitlementMethod::Accrual) {
                $accrued += $this->accrue($balance, $policy, $period, $date);
            }

            return ['granted' => $granted, 'accrued' => $accrued];
        }));
    }

    private function accrue(LeaveBalance $balance, LeavePolicy $policy, LeaveEntitlementPeriod $period, CarbonImmutable $date): int
    {
        $per = (int) $policy->accrual_minutes;
        if ($per <= 0) {
            return 0;
        }

        $start = CarbonImmutable::parse($period->starts_on)->startOfDay();
        $end = CarbonImmutable::parse($period->ends_on)->startOfDay();
        $ceiling = $date->lessThan($end) ? $date : $end;

        if ($policy->accrual_frequency === AccrualFrequency::Annual) {
            if ($date->greaterThanOrEqualTo($start)) {
                return $this->grantOnce($balance, $policy, $period, $per, 'accrual:'.$period->getKey().':annual', $start, 'accrual');
            }

            return 0;
        }

        if ($policy->accrual_frequency !== AccrualFrequency::Monthly) {
            return 0;
        }

        $accrued = 0;
        $anchor = $start;
        $guard = 0;
        while ($anchor->lessThanOrEqualTo($ceiling) && $guard < 120) {
            $key = 'accrual:'.$period->getKey().':'.$anchor->format('Y-m');
            $accrued += $this->grantOnce($balance, $policy, $period, $per, $key, $anchor, 'accrual');
            $anchor = $anchor->addMonth();
            $guard++;
        }

        return $accrued;
    }

    /**
     * Post a capped grant/accrual once (idempotent by key). Returns minutes added.
     */
    private function grantOnce(LeaveBalance $balance, LeavePolicy $policy, LeaveEntitlementPeriod $period, int $minutes, string $key, CarbonImmutable $effective, string $kind): int
    {
        if ($minutes <= 0) {
            return 0;
        }

        $amount = $this->capped($balance, $policy, $minutes);
        if ($amount <= 0) {
            return 0;
        }

        $opts = [
            'leave_policy_id' => $policy->getKey(),
            'idempotency_key' => $key,
            'effective_date' => $effective,
        ];

        $before = (int) $balance->version;
        $kind === 'grant'
            ? $this->balances->grant($balance, $amount, $opts)
            : $this->balances->accrue($balance, $amount, $opts);

        // version unchanged → the key already existed (no-op).
        return (int) $balance->version > $before ? $amount : 0;
    }

    /** Cap an increment so granted+accrued+carried+adjusted never exceeds max_balance. */
    private function capped(LeaveBalance $balance, LeavePolicy $policy, int $minutes): int
    {
        if ($policy->max_balance_minutes === null) {
            return $minutes;
        }

        $current = (int) $balance->granted_minutes + (int) $balance->accrued_minutes
            + (int) $balance->carried_minutes + (int) $balance->adjusted_minutes;
        $headroom = (int) $policy->max_balance_minutes - $current;

        return max(0, min($minutes, $headroom));
    }
}
