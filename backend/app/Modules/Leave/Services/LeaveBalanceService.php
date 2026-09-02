<?php

namespace App\Modules\Leave\Services;

use App\Modules\Leave\Enums\LedgerTransactionType;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveBalanceTransaction;
use App\Modules\Leave\Models\LeaveEntitlementPeriod;
use App\Modules\Leave\Support\LeaveLock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The balance accounting core. The immutable ledger (leave_balance_transactions)
 * is the source of truth; leave_balances is a transactionally-maintained
 * projection whose row also serves as the concurrency lock. Every mutation posts
 * a signed ledger row and applies the SAME signed delta to the matching bucket,
 * then recomputes available = granted + accrued + carried + adjusted − used −
 * reserved − expired. A reservation becomes usage via release(−reserved) +
 * usage(+used) in one call, so availability is never deducted twice.
 */
class LeaveBalanceService
{
    /** transaction_type => projection bucket column. */
    private const BUCKET = [
        'grant' => 'granted_minutes',
        'accrual' => 'accrued_minutes',
        'carry_forward' => 'carried_minutes',
        'expiry' => 'expired_minutes',
        'reservation' => 'reserved_minutes',
        'reservation_release' => 'reserved_minutes',
        'usage' => 'used_minutes',
        'usage_reversal' => 'used_minutes',
        'adjustment' => 'adjusted_minutes',
        'adjustment_reversal' => 'adjusted_minutes',
    ];

    /**
     * Run $callback with an advisory lock (leave:{tenant}:{employee}) held AND
     * the projection row locked FOR UPDATE. Must run inside a DB transaction.
     * The balance row is created on first use.
     */
    public function withLockedBalance(
        LeaveEntitlementPeriod $period,
        Closure $callback,
    ): mixed {
        LeaveLock::forEmployee((string) $period->tenant_id, (string) $period->employee_id);

        $balance = LeaveBalance::query()
            ->where('employee_id', $period->employee_id)
            ->where('leave_type_id', $period->leave_type_id)
            ->where('entitlement_period_id', $period->getKey())
            ->lockForUpdate()
            ->first();

        if ($balance === null) {
            $balance = LeaveBalance::query()->create([
                'employee_id' => $period->employee_id,
                'leave_type_id' => $period->leave_type_id,
                'entitlement_period_id' => $period->getKey(),
            ]);
            $balance = LeaveBalance::query()->whereKey($balance->getKey())->lockForUpdate()->first();
        }

        return $callback($balance);
    }

    /**
     * Post one signed ledger transaction and apply its delta to the projection.
     * $signedMinutes is the delta to the transaction's bucket (e.g. reservation
     * +m, reservation_release −m, usage +m, usage_reversal −m). Idempotent when
     * $idempotencyKey is given: a duplicate key is a no-op returning the existing
     * row. Assumes the balance row is already locked (see withLockedBalance).
     */
    public function post(
        LeaveBalance $balance,
        LedgerTransactionType $type,
        int $signedMinutes,
        array $opts = [],
    ): LeaveBalanceTransaction {
        $idempotencyKey = $opts['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = LeaveBalanceTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $existing; // already applied — no double count
            }
        }

        $transaction = LeaveBalanceTransaction::query()->create([
            'employee_id' => $balance->employee_id,
            'leave_type_id' => $balance->leave_type_id,
            'leave_policy_id' => $opts['leave_policy_id'] ?? null,
            'entitlement_period_id' => $balance->entitlement_period_id,
            'leave_request_id' => $opts['leave_request_id'] ?? null,
            'transaction_type' => $type,
            'minutes' => $signedMinutes,
            'effective_date' => ($opts['effective_date'] ?? CarbonImmutable::now())->toDateString(),
            'reference_type' => $opts['reference_type'] ?? null,
            'reference_id' => $opts['reference_id'] ?? null,
            'reason' => $opts['reason'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'metadata' => $opts['metadata'] ?? null,
            'created_by_user_id' => $opts['created_by_user_id'] ?? null,
        ]);

        $bucket = self::BUCKET[$type->value];
        $balance->{$bucket} = (int) $balance->{$bucket} + $signedMinutes;
        $balance->available_minutes = $this->availableFromBuckets($balance);
        $balance->version = (int) $balance->version + 1;
        $balance->save();

        return $transaction;
    }

    // --- Typed helpers (correct sign per bucket) ---

    public function grant(LeaveBalance $b, int $m, array $o = []): LeaveBalanceTransaction
    {
        return $this->post($b, LedgerTransactionType::Grant, abs($m), $o);
    }

    public function accrue(LeaveBalance $b, int $m, array $o = []): LeaveBalanceTransaction
    {
        return $this->post($b, LedgerTransactionType::Accrual, abs($m), $o);
    }

    public function carryForward(LeaveBalance $b, int $m, array $o = []): LeaveBalanceTransaction
    {
        return $this->post($b, LedgerTransactionType::CarryForward, abs($m), $o);
    }

    public function expire(LeaveBalance $b, int $m, array $o = []): LeaveBalanceTransaction
    {
        return $this->post($b, LedgerTransactionType::Expiry, abs($m), $o);
    }

    public function reserve(LeaveBalance $b, int $m, array $o = []): LeaveBalanceTransaction
    {
        return $this->post($b, LedgerTransactionType::Reservation, abs($m), $o);
    }

    public function releaseReservation(LeaveBalance $b, int $m, array $o = []): LeaveBalanceTransaction
    {
        return $this->post($b, LedgerTransactionType::ReservationRelease, -abs($m), $o);
    }

    public function consume(LeaveBalance $b, int $m, array $o = []): LeaveBalanceTransaction
    {
        return $this->post($b, LedgerTransactionType::Usage, abs($m), $o);
    }

    public function reverseUsage(LeaveBalance $b, int $m, array $o = []): LeaveBalanceTransaction
    {
        return $this->post($b, LedgerTransactionType::UsageReversal, -abs($m), $o);
    }

    /** Signed adjustment (+ adds entitlement, − reduces it). */
    public function adjust(LeaveBalance $b, int $signedMinutes, array $o = []): LeaveBalanceTransaction
    {
        return $this->post($b, LedgerTransactionType::Adjustment, $signedMinutes, $o);
    }

    /** available = granted + accrued + carried + adjusted − used − reserved − expired. */
    public function availableFromBuckets(LeaveBalance $b): int
    {
        return (int) $b->granted_minutes
            + (int) $b->accrued_minutes
            + (int) $b->carried_minutes
            + (int) $b->adjusted_minutes
            - (int) $b->used_minutes
            - (int) $b->reserved_minutes
            - (int) $b->expired_minutes;
    }

    /**
     * Recompute the projection from the ledger (maintenance / test invariant).
     * Returns the refreshed balance. Assumes the row is locked by the caller.
     */
    public function rebuildProjection(LeaveBalance $balance): LeaveBalance
    {
        $sums = LeaveBalanceTransaction::query()
            ->where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('entitlement_period_id', $balance->entitlement_period_id)
            ->selectRaw('transaction_type, COALESCE(SUM(minutes),0) as total')
            ->groupBy('transaction_type')
            ->pluck('total', 'transaction_type');

        $buckets = [
            'granted_minutes' => 0, 'accrued_minutes' => 0, 'carried_minutes' => 0,
            'adjusted_minutes' => 0, 'used_minutes' => 0, 'reserved_minutes' => 0,
            'expired_minutes' => 0,
        ];

        foreach ($sums as $type => $total) {
            $bucket = self::BUCKET[$type] ?? null;
            if ($bucket !== null) {
                $buckets[$bucket] += (int) $total;
            }
        }

        $balance->fill($buckets);
        $balance->available_minutes = $this->availableFromBuckets($balance);
        $balance->save();

        return $balance;
    }

    /** Recompute + persist directly for a period id (used by rebuild command/tests). */
    public function rebuildForPeriod(LeaveEntitlementPeriod $period): LeaveBalance
    {
        return DB::transaction(fn () => $this->withLockedBalance(
            $period,
            fn (LeaveBalance $b) => $this->rebuildProjection($b),
        ));
    }
}
