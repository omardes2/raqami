<?php

namespace App\Modules\Leave\Services;

use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveEntitlementPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Closes ended entitlement periods for the ACTIVE tenant: carries eligible
 * balance forward into the next period and expires the non-carried remainder —
 * all via the immutable ledger (never a silent reset). Idempotent (stable keys);
 * a closed period is skipped on re-run. No cron (a scheduler/command invokes it).
 */
class LeavePeriodClosureService
{
    public function __construct(
        private readonly LeavePolicyResolver $policyResolver,
        private readonly LeaveEntitlementPeriodService $periods,
        private readonly LeaveBalanceService $balances,
    ) {}

    /** @return array{closed:int, carried:int, expired:int} */
    public function processForDate(CarbonImmutable $date): array
    {
        $counts = ['closed' => 0, 'carried' => 0, 'expired' => 0];

        LeaveEntitlementPeriod::query()
            ->where('status', 'open')
            ->whereDate('ends_on', '<', $date->toDateString())
            ->with('employee')
            ->orderBy('id')
            ->chunkById(200, function ($periods) use (&$counts) {
                foreach ($periods as $period) {
                    $result = $this->closePeriod($period);
                    $counts['closed']++;
                    $counts['carried'] += $result['carried'];
                    $counts['expired'] += $result['expired'];
                }
            });

        return $counts;
    }

    /** @return array{carried:int, expired:int} */
    private function closePeriod(LeaveEntitlementPeriod $period): array
    {
        $employee = $period->employee;
        $endsOn = CarbonImmutable::parse($period->ends_on)->startOfDay();
        $policy = $employee !== null
            ? $this->policyResolver->resolve($employee, (string) $period->leave_type_id, $endsOn)
            : null;

        $outcome = DB::transaction(fn () => $this->balances->withLockedBalance($period, function (LeaveBalance $balance) use ($period, $policy, $endsOn) {
            $available = (int) $balance->available_minutes;
            $carried = 0;

            if ($available > 0 && $policy !== null && $policy->carry_forward_enabled) {
                $max = $policy->carry_forward_max_minutes;
                $carried = $max === null ? $available : min($available, (int) $max);
            }

            $expired = max(0, $available - $carried);
            if ($expired > 0) {
                $this->balances->expire($balance, $expired, [
                    'leave_policy_id' => $policy?->getKey(),
                    'idempotency_key' => 'expiry:'.$period->getKey(),
                    'effective_date' => $endsOn,
                ]);
            }

            $period->fill(['status' => 'closed'])->save();

            return ['carried' => $carried, 'expired' => $expired];
        }));

        // Carry the eligible balance into the next period (separate lock).
        if ($outcome['carried'] > 0 && $employee !== null) {
            $nextDate = $endsOn->addDay();
            $next = $this->periods->resolveOrCreate($employee, (string) $period->leave_type_id, $policy, $nextDate);

            DB::transaction(fn () => $this->balances->withLockedBalance($next, function (LeaveBalance $balance) use ($outcome, $policy, $period, $next) {
                $this->balances->carryForward($balance, $outcome['carried'], [
                    'leave_policy_id' => $policy?->getKey(),
                    'idempotency_key' => 'carry:'.$period->getKey(),
                    'effective_date' => CarbonImmutable::parse($next->starts_on),
                    'metadata' => ['from_period_id' => (string) $period->getKey()],
                ]);
            }));
        }

        return $outcome;
    }
}
