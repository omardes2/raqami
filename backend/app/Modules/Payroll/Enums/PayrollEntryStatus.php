<?php

namespace App\Modules\Payroll\Enums;

/**
 * Lifecycle of a single employee's payroll entry. Phase 2A produces only
 * `calculated` or `failed`; `finalized` is defined for a later phase and is never
 * set by calculation.
 */
enum PayrollEntryStatus: string
{
    case Pending = 'pending';
    case Calculated = 'calculated';
    case Failed = 'failed';
    case Finalized = 'finalized';

    public function isFinalized(): bool
    {
        return $this === self::Finalized;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
