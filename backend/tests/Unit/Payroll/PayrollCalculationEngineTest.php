<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Calculation\Input\BaseSegment;
use App\Modules\Payroll\Calculation\Input\CalculationInput;
use App\Modules\Payroll\Calculation\Input\ComponentSegment;
use App\Modules\Payroll\Calculation\Input\OvertimeItem;
use App\Modules\Payroll\Calculation\Input\UnpaidLeaveSegment;
use App\Modules\Payroll\Calculation\PayrollCalculationEngine;
use App\Modules\Payroll\Enums\PayrollComponentMode;
use App\Modules\Payroll\Enums\PayrollComponentType;
use App\Modules\Payroll\Enums\PayrollLineType;
use PHPUnit\Framework\TestCase;

/** Pure-math tests for the calculation engine (no DB, no framework). */
class PayrollCalculationEngineTest extends TestCase
{
    private const PERIOD = 9600; // 20 days * 8h * 60m

    private function engine(): PayrollCalculationEngine
    {
        return new PayrollCalculationEngine;
    }

    private function input(array $overrides = []): CalculationInput
    {
        return new CalculationInput(
            currency: $overrides['currency'] ?? 'USD',
            periodExpectedMinutes: $overrides['period'] ?? self::PERIOD,
            baseSegments: $overrides['base'] ?? [],
            componentSegments: $overrides['components'] ?? [],
            unpaidLeaveSegments: $overrides['unpaid'] ?? [],
            overtimeItems: $overrides['overtime'] ?? [],
            overtimeEnabled: $overrides['overtimeEnabled'] ?? true,
        );
    }

    public function test_full_month_base_equals_exact_monthly(): void
    {
        $r = $this->engine()->calculate($this->input([
            'base' => [new BaseSegment('c1', 300000, self::PERIOD, '2026-09-01', null)],
        ]));
        $this->assertSame('USD', $r->currency);
        $this->assertSame(300000, $r->grossMinor);
        $this->assertSame(0, $r->deductionMinor);
        $this->assertSame(300000, $r->netMinor);
        $this->assertCount(1, $r->lines);
        $this->assertSame(PayrollLineType::BaseSalary, $r->lines[0]->type);
    }

    public function test_half_month_base_is_prorated(): void
    {
        $r = $this->engine()->calculate($this->input([
            'base' => [new BaseSegment('c1', 300000, 4800, '2026-09-15', null)],
        ]));
        $this->assertSame(150000, $r->grossMinor);
    }

    public function test_salary_change_sums_two_segments(): void
    {
        $r = $this->engine()->calculate($this->input([
            'base' => [
                new BaseSegment('c1', 300000, 4800, '2026-09-01', '2026-09-15'),
                new BaseSegment('c2', 360000, 4800, '2026-09-16', null),
            ],
        ]));
        // 150000 + 180000
        $this->assertSame(330000, $r->grossMinor);
        $this->assertCount(2, $r->lines);
    }

    public function test_fixed_and_percent_components(): void
    {
        $r = $this->engine()->calculate($this->input([
            'base' => [new BaseSegment('c1', 300000, self::PERIOD, '2026-09-01', null)],
            'components' => [
                new ComponentSegment('a1', 'k1', 'HOUSING', 'Housing', PayrollComponentType::Earning, PayrollComponentMode::Fixed, self::PERIOD, 300000, 50000, null, '2026-09-01', null),
                new ComponentSegment('a2', 'k2', 'GOSI', 'GOSI', PayrollComponentType::Deduction, PayrollComponentMode::PercentOfBase, self::PERIOD, 300000, null, 500, '2026-09-01', null),
            ],
        ]));
        // base 300000 + housing 50000 = gross 350000; GOSI 5% of 300000 = 15000 deduction
        $this->assertSame(350000, $r->grossMinor);
        $this->assertSame(15000, $r->deductionMinor);
        $this->assertSame(335000, $r->netMinor);
    }

    public function test_percent_bps_precision(): void
    {
        // 1250 bps = 12.5% of 300000 = 37500
        $r = $this->engine()->calculate($this->input([
            'base' => [new BaseSegment('c1', 300000, self::PERIOD, '2026-09-01', null)],
            'components' => [
                new ComponentSegment('a1', 'k1', 'X', 'X', PayrollComponentType::Earning, PayrollComponentMode::PercentOfBase, self::PERIOD, 300000, null, 1250, '2026-09-01', null),
            ],
        ]));
        $this->assertSame(337500, $r->grossMinor);
    }

    public function test_overtime_rate_over_60(): void
    {
        $r = $this->engine()->calculate($this->input([
            'base' => [new BaseSegment('c1', 300000, self::PERIOD, '2026-09-01', null)],
            'overtime' => [new OvertimeItem('o1', '2026-09-10', 90, 3000)], // 3000/hr * 90m /60 = 4500
        ]));
        $this->assertSame(304500, $r->grossMinor);
    }

    public function test_overtime_disabled_produces_no_line(): void
    {
        $r = $this->engine()->calculate($this->input([
            'base' => [new BaseSegment('c1', 300000, self::PERIOD, '2026-09-01', null)],
            'overtime' => [new OvertimeItem('o1', '2026-09-10', 90, 3000)],
            'overtimeEnabled' => false,
        ]));
        $this->assertSame(300000, $r->grossMinor);
    }

    public function test_unpaid_leave_deduction_and_no_double_count(): void
    {
        // Base numerator is FULL period (unpaid leave does not reduce it); unpaid is
        // its own deduction: 480 min at 300000/9600 = 15000.
        $r = $this->engine()->calculate($this->input([
            'base' => [new BaseSegment('c1', 300000, self::PERIOD, '2026-09-01', null)],
            'unpaid' => [new UnpaidLeaveSegment('lr1', 300000, 480, '2026-09-10', '2026-09-10')],
        ]));
        $this->assertSame(300000, $r->grossMinor);
        $this->assertSame(15000, $r->deductionMinor);
        $this->assertSame(285000, $r->netMinor);
    }

    public function test_negative_net_is_allowed_and_warned(): void
    {
        $r = $this->engine()->calculate($this->input([
            'base' => [new BaseSegment('c1', 10000, self::PERIOD, '2026-09-01', null)],
            'unpaid' => [new UnpaidLeaveSegment('lr1', 10000, self::PERIOD, '2026-09-01', '2026-09-30')],
            'components' => [
                new ComponentSegment('a1', 'k1', 'FEE', 'Fee', PayrollComponentType::Deduction, PayrollComponentMode::Fixed, self::PERIOD, 10000, 5000, null, '2026-09-01', null),
            ],
        ]));
        // gross 10000; deduction 10000 (unpaid full) + 5000 (fee) = 15000; net -5000
        $this->assertSame(10000, $r->grossMinor);
        $this->assertSame(15000, $r->deductionMinor);
        $this->assertSame(-5000, $r->netMinor);
        $this->assertContains('negative_net', $r->warnings);
    }
}
