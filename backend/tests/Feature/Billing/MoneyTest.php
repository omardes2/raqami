<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Support\CurrencyMetadata;
use Tests\TestCase;

/** Currency exponent metadata + minor-unit formatting (spec §5). */
class MoneyTest extends TestCase
{
    public function test_exponents_are_correct(): void
    {
        $this->assertSame(2, CurrencyMetadata::exponent('USD'));
        $this->assertSame(2, CurrencyMetadata::exponent('ILS'));
        $this->assertSame(3, CurrencyMetadata::exponent('JOD'));
        $this->assertSame(2, CurrencyMetadata::exponent('ZZZ')); // default
    }

    public function test_formats_minor_units_by_exponent(): void
    {
        $this->assertSame('19.99', CurrencyMetadata::format(1999, 'USD'));
        $this->assertSame('19.99', CurrencyMetadata::format(1999, 'ILS'));
        $this->assertSame('1.999', CurrencyMetadata::format(1999, 'JOD')); // 3 decimals
        $this->assertSame('1.000', CurrencyMetadata::format(1000, 'JOD'));
        $this->assertSame('10.00', CurrencyMetadata::format(1000, 'USD'));
    }

    public function test_format_with_code(): void
    {
        $this->assertSame('19.99 USD', CurrencyMetadata::formatWithCode(1999, 'USD'));
        $this->assertSame('1.999 JOD', CurrencyMetadata::formatWithCode(1999, 'JOD'));
    }
}
