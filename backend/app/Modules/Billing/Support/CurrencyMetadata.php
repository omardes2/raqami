<?php

namespace App\Modules\Billing\Support;

/**
 * Central currency metadata + minor-unit formatting (spec §5). Authoritative
 * arithmetic ALWAYS stays in integer minor units; this only converts a stored
 * minor amount to a display string using the currency's ISO exponent, so
 * 3-decimal currencies (e.g. JOD) render correctly instead of assuming /100.
 */
class CurrencyMetadata
{
    /** Minor-unit exponent for a currency (defaults to 2 when unconfigured). */
    public static function exponent(string $currency): int
    {
        $map = (array) config('billing.currency_exponents', []);

        return (int) ($map[strtoupper($currency)] ?? 2);
    }

    /** Format an integer minor amount for display, e.g. (1999,'JOD') => "1.999". */
    public static function format(int $minor, string $currency): string
    {
        $exp = self::exponent($currency);
        if ($exp === 0) {
            return (string) $minor;
        }
        $divisor = 10 ** $exp;
        $major = intdiv(abs($minor), $divisor);
        $frac = abs($minor) % $divisor;
        $sign = $minor < 0 ? '-' : '';

        return $sign.$major.'.'.str_pad((string) $frac, $exp, '0', STR_PAD_LEFT);
    }

    /** Format with the currency code appended, e.g. "1.999 JOD". */
    public static function formatWithCode(int $minor, string $currency): string
    {
        return self::format($minor, $currency).' '.strtoupper($currency);
    }
}
