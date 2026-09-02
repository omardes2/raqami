<?php

namespace App\Modules\Leave\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveBalanceTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Authorized HR/Admin manual balance adjustments. Never a direct balance write —
 * a signed immutable `adjustment` ledger row (reason mandatory). Pushing the
 * balance negative requires the negative-override capability (passed by the
 * controller after the leave.negative_override scope check).
 */
class LeaveAdjustmentService
{
    public function __construct(
        private readonly LeaveEntitlementPeriodService $periods,
        private readonly LeavePolicyResolver $policyResolver,
        private readonly LeaveBalanceService $balances,
    ) {}

    public function adjust(
        Employee $employee,
        string $leaveTypeId,
        int $signedMinutes,
        string $reason,
        Model $actor,
        ?CarbonImmutable $effectiveDate = null,
        bool $allowNegativeOverride = false,
    ): LeaveBalanceTransaction {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => [__('leave.adjustment_reason_required')]]);
        }

        $date = $effectiveDate ?? CarbonImmutable::now();
        $policy = $this->policyResolver->resolve($employee, $leaveTypeId, $date);
        $period = $this->periods->resolveOrCreate($employee, $leaveTypeId, $policy, $date);

        return DB::transaction(function () use ($period, $signedMinutes, $reason, $actor, $date, $policy, $allowNegativeOverride) {
            return $this->balances->withLockedBalance($period, function ($balance) use (
                $signedMinutes, $reason, $actor, $date, $policy, $allowNegativeOverride
            ) {
                $projected = (int) $balance->available_minutes + $signedMinutes;

                if ($projected < 0 && ! $allowNegativeOverride) {
                    $allowsNegative = $policy !== null && $policy->allow_negative_balance
                        && ($policy->max_negative_minutes === null || $projected >= -((int) $policy->max_negative_minutes));
                    if (! $allowsNegative) {
                        throw ValidationException::withMessages(['leave' => [__('leave.negative_override_forbidden')]]);
                    }
                }

                return $this->balances->adjust($balance, $signedMinutes, [
                    'leave_policy_id' => $policy?->getKey(),
                    'reason' => $reason,
                    'effective_date' => $date,
                    'created_by_user_id' => (string) $actor->getKey(),
                    'metadata' => ['manual' => true],
                ]);
            });
        });
    }
}
