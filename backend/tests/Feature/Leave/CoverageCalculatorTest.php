<?php

namespace Tests\Feature\Leave;

use App\Modules\Leave\Enums\LeaveRequestKind;
use App\Modules\Leave\Support\CoverageCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Deterministic half-day geometry (D1): halves measured in work minutes, the
 * boundary may fall between or inside a segment, odd totals split ceil/floor.
 */
class CoverageCalculatorTest extends TestCase
{
    private function seg(string $start, string $end): array
    {
        return [CarbonImmutable::parse($start, 'UTC'), CarbonImmutable::parse($end, 'UTC')];
    }

    public function test_straight_day_full_and_halves(): void
    {
        $calc = new CoverageCalculator;
        $day = [$this->seg('2027-10-01 08:00', '2027-10-01 16:00')]; // 480

        $this->assertSame(480, $calc->coverage($day, LeaveRequestKind::FullDay)['minutes']);

        $first = $calc->coverage($day, LeaveRequestKind::FirstHalf);
        $this->assertSame(240, $first['minutes']);
        $this->assertStringContainsString('08:00', $first['intervals'][0]['start_at']);
        $this->assertStringContainsString('12:00', $first['intervals'][0]['end_at']);

        $second = $calc->coverage($day, LeaveRequestKind::SecondHalf);
        $this->assertSame(240, $second['minutes']);
        $this->assertStringContainsString('12:00', $second['intervals'][0]['start_at']);
        $this->assertStringContainsString('16:00', $second['intervals'][0]['end_at']);
    }

    public function test_split_shift_halves_land_between_segments(): void
    {
        $calc = new CoverageCalculator;
        $day = [
            $this->seg('2027-10-01 08:00', '2027-10-01 12:00'), // 240
            $this->seg('2027-10-01 16:00', '2027-10-01 20:00'), // 240
        ];

        $first = $calc->coverage($day, LeaveRequestKind::FirstHalf);
        $this->assertSame(240, $first['minutes']);
        $this->assertCount(1, $first['intervals']);
        $this->assertStringContainsString('08:00', $first['intervals'][0]['start_at']);
        $this->assertStringContainsString('12:00', $first['intervals'][0]['end_at']);

        $second = $calc->coverage($day, LeaveRequestKind::SecondHalf);
        $this->assertSame(240, $second['minutes']);
        $this->assertCount(1, $second['intervals']);
        $this->assertStringContainsString('16:00', $second['intervals'][0]['start_at']);
        $this->assertStringContainsString('20:00', $second['intervals'][0]['end_at']);
    }

    public function test_odd_total_splits_ceil_then_floor(): void
    {
        $calc = new CoverageCalculator;
        $day = [$this->seg('2027-10-01 08:00', '2027-10-01 14:45')]; // 405

        $first = $calc->coverage($day, LeaveRequestKind::FirstHalf);
        $second = $calc->coverage($day, LeaveRequestKind::SecondHalf);

        $this->assertSame(203, $first['minutes']);  // ceil(405/2)
        $this->assertSame(202, $second['minutes']); // floor(405/2)
        $this->assertSame(405, $first['minutes'] + $second['minutes']);
        // Split lands inside the single segment at 11:23.
        $this->assertStringContainsString('11:23', $first['intervals'][0]['end_at']);
        $this->assertStringContainsString('11:23', $second['intervals'][0]['start_at']);
    }

    public function test_midpoint_inside_segment(): void
    {
        $calc = new CoverageCalculator;
        $day = [$this->seg('2027-10-01 08:00', '2027-10-01 15:00')]; // 420, half 210 → 11:30

        $first = $calc->coverage($day, LeaveRequestKind::FirstHalf);
        $this->assertSame(210, $first['minutes']);
        $this->assertStringContainsString('11:30', $first['intervals'][0]['end_at']);
    }
}
