<?php

namespace App\Modules\Payroll\Enums;

/**
 * The bounded payroll-run lifecycle (Correction C). Calculation and finalization
 * themselves arrive in later phases; Phase 1 defines the states and the legal
 * transitions so nothing invents an ad-hoc status.
 *
 *   draft → calculating → calculated | calculation_failed
 *   calculation_failed → calculating
 *   calculated → calculating | approved | finalized | cancelled
 *   approved → finalized | cancelled
 *   finalized (terminal, immutable)   cancelled (terminal for that run)
 */
enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Calculating = 'calculating';
    case CalculationFailed = 'calculation_failed';
    case Calculated = 'calculated';
    case Approved = 'approved';
    case Finalized = 'finalized';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Finalized || $this === self::Cancelled;
    }

    /** Legal successor states from the current one. */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Draft => $to === self::Calculating || $to === self::Cancelled,
            self::Calculating => in_array($to, [self::Calculated, self::CalculationFailed], true),
            self::CalculationFailed => $to === self::Calculating || $to === self::Cancelled,
            self::Calculated => in_array($to, [self::Calculating, self::Approved, self::Finalized, self::Cancelled], true),
            self::Approved => $to === self::Finalized || $to === self::Cancelled,
            self::Finalized, self::Cancelled => false,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
