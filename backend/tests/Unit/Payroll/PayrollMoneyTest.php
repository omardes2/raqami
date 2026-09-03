<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Support\PayrollMoney;
use App\Modules\Payroll\Support\PayrollMoneyException;
use App\Support\Money\CurrencyMetadata;
use Tests\TestCase;

/**
 * PayrollMoney is integer-only (no float / no round()) with deterministic
 * half-up (away from zero) rounding, and overflow-safe mulDiv. These are the
 * financial-correctness primitives every payroll calculation depends on (D8).
 */
class PayrollMoneyTest extends TestCase
{
    public function test_round_half_up_positive_boundaries(): void
    {
        $this->assertSame(0, PayrollMoney::roundHalfUp(2, 5));   // 0.4 -> 0 (below half)
        $this->assertSame(1, PayrollMoney::roundHalfUp(1, 2));   // 0.5 -> 1 (exact half up)
        $this->assertSame(1, PayrollMoney::roundHalfUp(3, 5));   // 0.6 -> 1 (above half)
        $this->assertSame(3, PayrollMoney::roundHalfUp(5, 2));   // 2.5 -> 3 (exact half up)
        $this->assertSame(2, PayrollMoney::roundHalfUp(12, 5));  // 2.4 -> 2
    }

    public function test_round_half_up_negative_boundaries(): void
    {
        $this->assertSame(0, PayrollMoney::roundHalfUp(-2, 5));   // -0.4 -> 0
        $this->assertSame(-1, PayrollMoney::roundHalfUp(-1, 2));  // -0.5 -> -1 (away from zero)
        $this->assertSame(-1, PayrollMoney::roundHalfUp(-3, 5));  // -0.6 -> -1
        $this->assertSame(-3, PayrollMoney::roundHalfUp(-5, 2));  // -2.5 -> -3 (away from zero)
    }

    public function test_round_half_up_exact_and_zero(): void
    {
        $this->assertSame(0, PayrollMoney::roundHalfUp(0, 7));
        $this->assertSame(4, PayrollMoney::roundHalfUp(8, 2));
        $this->assertSame(-4, PayrollMoney::roundHalfUp(-8, 2));
    }

    public function test_zero_denominator_rejected(): void
    {
        $this->expectException(PayrollMoneyException::class);
        PayrollMoney::roundHalfUp(10, 0);
    }

    public function test_negative_denominator_rejected(): void
    {
        $this->expectException(PayrollMoneyException::class);
        PayrollMoney::roundHalfUp(10, -3);
    }

    public function test_mul_div_proration_and_percent(): void
    {
        // Full-month proration: 4000.00 * 5000/10000 minutes = 2000.00.
        $this->assertSame(200000, PayrollMoney::mulDivHalfUp(400000, 5000, 10000));
        // Percent-of-base via basis points: 5% of 4000.00 = 200.00 (500 bps).
        $this->assertSame(20000, PayrollMoney::mulDivHalfUp(400000, 500, 10000));
        // 12.5% of 4000.00 = 500.00 (1250 bps).
        $this->assertSame(50000, PayrollMoney::mulDivHalfUp(400000, 1250, 10000));
        // Overtime: 90 approved minutes at 60.00/hr = 90*6000/60 = 90.00.
        $this->assertSame(9000, PayrollMoney::mulDivHalfUp(6000, 90, 60));
    }

    public function test_mul_div_half_up_rounding(): void
    {
        // 1000 * 1 / 3 = 333.33.. -> 333 (below half).
        $this->assertSame(333, PayrollMoney::mulDivHalfUp(1000, 1, 3));
        // 1000 * 2 / 3 = 666.66.. -> 667 (above half).
        $this->assertSame(667, PayrollMoney::mulDivHalfUp(1000, 2, 3));
        // Negative: -1000 * 2 / 3 = -666.66 -> -667.
        $this->assertSame(-667, PayrollMoney::mulDivHalfUp(-1000, 2, 3));
    }

    public function test_mul_div_reduces_factors_to_avoid_overflow(): void
    {
        // PHP_INT_MAX * 2 / 2 must reduce to PHP_INT_MAX, not overflow.
        $this->assertSame(PHP_INT_MAX, PayrollMoney::mulDivHalfUp(PHP_INT_MAX, 2, 2));
    }

    public function test_mul_div_throws_on_genuine_overflow(): void
    {
        $this->expectException(PayrollMoneyException::class);
        // gcd reductions cannot help: PHP_INT_MAX * 3 overflows.
        PayrollMoney::mulDivHalfUp(PHP_INT_MAX, 3, 2);
    }

    public function test_currency_exponent_metadata(): void
    {
        $this->assertSame(3, CurrencyMetadata::exponent('JOD'));
        $this->assertSame(2, CurrencyMetadata::exponent('USD'));
        $this->assertSame(2, CurrencyMetadata::exponent('ZZZ')); // default
        $this->assertSame('1.999', CurrencyMetadata::format(1999, 'JOD'));
        $this->assertSame('19.99', CurrencyMetadata::format(1999, 'USD'));
        $this->assertSame('USD', CurrencyMetadata::normalize(' usd '));
    }
}
