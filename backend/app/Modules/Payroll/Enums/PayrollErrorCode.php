<?php

namespace App\Modules\Payroll\Enums;

/**
 * Bounded, safe error codes for an entry that could not be calculated. A failed
 * entry stays visible in Run Review with its code. error_context carries only
 * ids/dates/codes — never salary amounts.
 */
enum PayrollErrorCode: string
{
    case MissingCompensation = 'missing_compensation';
    case OverlappingCompensation = 'overlapping_compensation';
    case InvalidEffectiveRange = 'invalid_effective_range';
    case CurrencyChangeInPeriod = 'currency_change_in_period';
    case ScheduleUnresolvable = 'schedule_unresolvable';
    case ZeroExpectedMinutes = 'zero_expected_minutes';
    case ComponentCurrencyMismatch = 'component_currency_mismatch';
    case UnclassifiedLeaveType = 'unclassified_leave_type';
    case OvertimeRateMissing = 'overtime_rate_missing';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
