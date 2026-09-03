<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Calculation\PayrollInputFingerprintService;
use PHPUnit\Framework\TestCase;

/** Determinism + change-sensitivity of the canonical input fingerprint. */
class PayrollInputFingerprintServiceTest extends TestCase
{
    private function service(): PayrollInputFingerprintService
    {
        return new PayrollInputFingerprintService;
    }

    private function snapshot(): array
    {
        return [
            'schema_version' => 1,
            'calculation_version' => 'core-v1',
            'period' => ['id' => 'p1', 'start' => '2026-09-01', 'end' => '2026-09-30', 'timezone' => 'UTC'],
            'compensations' => [
                ['id' => 'c1', 'version' => 1, 'currency' => 'USD', 'base_amount_minor' => 300000, 'effective_from' => '2026-09-01', 'effective_to' => null],
                ['id' => 'c2', 'version' => 3, 'currency' => 'USD', 'base_amount_minor' => 360000, 'effective_from' => '2026-09-16', 'effective_to' => null],
            ],
            'schedule' => ['period_expected_minutes' => 14400, 'days' => ['2026-09-01' => 480, '2026-09-02' => 480]],
        ];
    }

    public function test_same_input_same_fingerprint(): void
    {
        $s = $this->service();
        $this->assertSame($s->fingerprint($this->snapshot()), $s->fingerprint($this->snapshot()));
    }

    public function test_key_and_list_ordering_are_stable(): void
    {
        $s = $this->service();
        $a = $this->snapshot();

        $b = $this->snapshot();
        // Reverse the compensation list and reorder top-level keys.
        $b['compensations'] = array_reverse($b['compensations']);
        $b = array_reverse($b, true);

        $this->assertSame($s->fingerprint($a), $s->fingerprint($b));
    }

    public function test_relevant_salary_change_flips_fingerprint(): void
    {
        $s = $this->service();
        $a = $this->snapshot();
        $b = $this->snapshot();
        $b['compensations'][0]['base_amount_minor'] = 999999;

        $this->assertNotSame($s->fingerprint($a), $s->fingerprint($b));
    }

    public function test_schedule_change_flips_fingerprint(): void
    {
        $s = $this->service();
        $a = $this->snapshot();
        $b = $this->snapshot();
        $b['schedule']['days']['2026-09-01'] = 0;

        $this->assertNotSame($s->fingerprint($a), $s->fingerprint($b));
    }

    public function test_version_bump_flips_fingerprint(): void
    {
        $s = $this->service();
        $a = $this->snapshot();
        $b = $this->snapshot();
        $b['compensations'][1]['version'] = 4;

        $this->assertNotSame($s->fingerprint($a), $s->fingerprint($b));
    }
}
