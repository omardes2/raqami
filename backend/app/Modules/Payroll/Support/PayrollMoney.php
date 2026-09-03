<?php

namespace App\Modules\Payroll\Support;

/**
 * Integer-only money arithmetic for Payroll (D8, Correction J/K/M). NO PHP
 * float, NO round(), NO decimal source of truth — every operation is exact
 * integer/rational math with deterministic HALF-UP (away from zero on a .5
 * boundary) rounding to whole minor units.
 *
 * Overflow is a financial-integrity hazard, not a rounding detail: proration and
 * percent-of-base multiply an amount by minutes or basis points, which can
 * exceed PHP_INT_MAX. mulDivHalfUp reduces common factors first and then does a
 * CHECKED multiplication, throwing PayrollMoneyException rather than silently
 * wrapping. Callers must never form `amount * minutes * bps` themselves.
 */
final class PayrollMoney
{
    /**
     * Round numerator/denominator to the nearest integer, half away from zero.
     * denominator MUST be > 0. numerator may be negative.
     */
    public static function roundHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new PayrollMoneyException("Payroll rounding denominator must be positive, got {$denominator}.");
        }
        if ($numerator === 0) {
            return 0;
        }

        $negative = $numerator < 0;
        $absNum = self::absInt($numerator);

        // Reduce to limit magnitudes before the doubling comparison below.
        $g = self::gcd($absNum, $denominator);
        $absNum = intdiv($absNum, $g);
        $den = intdiv($denominator, $g);

        $quotient = intdiv($absNum, $den);
        $remainder = $absNum - ($quotient * $den);

        // Half-up: round away from zero when 2*remainder >= denominator.
        // Compare as remainder*2 >= den, guarding the doubling against overflow.
        if ($remainder > intdiv(PHP_INT_MAX, 2)) {
            // remainder < den <= original denominator; doubling overflow here would
            // require an astronomically large denominator — treat as round-up.
            $quotient += 1;
        } elseif (($remainder * 2) >= $den) {
            $quotient += 1;
        }

        return $negative ? -$quotient : $quotient;
    }

    /**
     * Compute round((a * b) / denominator) half-up, overflow-safe.
     * denominator MUST be > 0. Used for scheduled-minute proration
     * (base * minutes / expected) and percent-of-base (base * bps / 10000).
     */
    public static function mulDivHalfUp(int $a, int $b, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new PayrollMoneyException("Payroll mulDiv denominator must be positive, got {$denominator}.");
        }
        if ($a === 0 || $b === 0) {
            return 0;
        }

        $negative = ($a < 0) xor ($b < 0);
        $absA = self::absInt($a);
        $absB = self::absInt($b);

        // Reduce each factor against the denominator to shrink the product.
        $g1 = self::gcd($absA, $denominator);
        $absA = intdiv($absA, $g1);
        $den = intdiv($denominator, $g1);

        $g2 = self::gcd($absB, $den);
        $absB = intdiv($absB, $g2);
        $den = intdiv($den, $g2);

        $product = self::checkedMul($absA, $absB);
        $rounded = self::roundHalfUp($product, $den);

        return $negative ? -$rounded : $rounded;
    }

    /** Multiply two non-negative ints, throwing if the result would overflow. */
    private static function checkedMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        if ($a > intdiv(PHP_INT_MAX, $b)) {
            throw new PayrollMoneyException("Payroll money multiplication overflow: {$a} * {$b} exceeds PHP_INT_MAX.");
        }

        return $a * $b;
    }

    /** Greatest common divisor of two non-negative integers. */
    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 0 ? 1 : $a;
    }

    /** Absolute value guarded against PHP_INT_MIN overflow. */
    private static function absInt(int $value): int
    {
        if ($value === PHP_INT_MIN) {
            throw new PayrollMoneyException('Payroll money value out of representable range.');
        }

        return abs($value);
    }
}
