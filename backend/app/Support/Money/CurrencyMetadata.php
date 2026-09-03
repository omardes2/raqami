<?php

namespace App\Support\Money;

/**
 * Shared, module-neutral currency metadata + minor-unit formatting.
 *
 * Authoritative money arithmetic ALWAYS stays in integer minor units; this only
 * converts a stored minor amount to a display string using the currency's ISO
 * exponent, so 3-decimal currencies (e.g. JOD) render correctly instead of
 * assuming /100. Extracted from the Billing module (Sprint 2) so that other
 * modules — notably Payroll — can reuse currency exponents WITHOUT taking a
 * dependency on Billing. The exponent map still lives in one place
 * (config('billing.currency_exponents')); this class is the single reader.
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

    /** Normalize a currency code to an uppercase ISO-style 3-letter code. */
    public static function normalize(string $currency): string
    {
        return strtoupper(trim($currency));
    }
}
