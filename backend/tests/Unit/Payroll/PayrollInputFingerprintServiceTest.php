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

    public function test_object_key_ordering_is_stable(): void
    {
        $s = $this->service();
        $a = $this->snapshot();

        // Reordering OBJECT keys (recursively sorted) must not change the hash.
        $b = array_reverse($this->snapshot(), true);
        $b['period'] = array_reverse($b['period'], true);
        $b['compensations'][0] = array_reverse($b['compensations'][0], true);

        $this->assertSame($s->fingerprint($a), $s->fingerprint($b));
    }

    public function test_list_order_is_semantically_preserved(): void
    {
        $s = $this->service();
        $a = $this->snapshot();

        // Lists are canonically ordered by the BUILDER; the fingerprint PRESERVES
        // list order, so a genuinely reordered list is a different input (it must not
        // silently collapse to the same hash).
        $b = $this->snapshot();
        $b['compensations'] = array_reverse($b['compensations']);

        $this->assertNotSame($s->fingerprint($a), $s->fingerprint($b));
    }

    public function test_duplicates_and_numeric_string_distinction_preserved(): void
    {
        $s = $this->service();
        $dup = ['xs' => [1, 1, 2]];
        $single = ['xs' => [1, 2]];
        $this->assertNotSame($s->fingerprint($dup), $s->fingerprint($single), 'duplicates must be preserved');

        $intVal = ['v' => 1];
        $strVal = ['v' => '1'];
        $this->assertNotSame($s->fingerprint($intVal), $s->fingerprint($strVal), 'int 1 must differ from string "1"');
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
