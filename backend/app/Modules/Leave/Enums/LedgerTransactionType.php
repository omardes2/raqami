<?php

namespace App\Modules\Leave\Enums;

/**
 * Immutable leave ledger transaction types. `minutes` is signed relative to the
 * bucket each type targets; availability = granted + accrued + carried +
 * adjusted − used − reserved − expired. A reservation becomes usage via a
 * reservation_release (+reserved) plus a usage (+used) in one transaction, so
 * availability is deducted exactly once.
 */
enum LedgerTransactionType: string
{
    case Grant = 'grant';                       // +granted
    case Accrual = 'accrual';                   // +accrued
    case CarryForward = 'carry_forward';        // +carried
    case Expiry = 'expiry';                     // +expired (reduces availability)
    case Reservation = 'reservation';           // +reserved (reduces availability)
    case ReservationRelease = 'reservation_release'; // -reserved (restores availability)
    case Usage = 'usage';                       // +used (reduces availability)
    case UsageReversal = 'usage_reversal';      // -used (restores availability)
    case Adjustment = 'adjustment';             // +/-adjusted
    case AdjustmentReversal = 'adjustment_reversal'; // opposite of a prior adjustment

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
