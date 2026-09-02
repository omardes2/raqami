<?php

namespace App\Modules\Leave\Support;

use Carbon\CarbonImmutable;

/**
 * Pure half-open [start, end) interval arithmetic over UTC instants, used for
 * overlap detection (request conflicts) and expected-work subtraction (partial
 * leave attendance). Intervals are ['start_at' => ISO, 'end_at' => ISO].
 */
final class IntervalMath
{
    /** True if any interval in $a overlaps any interval in $b (half-open). */
    public static function overlaps(array $a, array $b): bool
    {
        foreach ($a as $x) {
            [$xs, $xe] = self::bounds($x);
            foreach ($b as $y) {
                [$ys, $ye] = self::bounds($y);
                if ($xs < $ye && $ys < $xe) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * $expected minus $covered → remaining intervals (what is still expected).
     * Both are arrays of ['start_at','end_at']; result normalized + ordered.
     *
     * @return array<int, array{start_at:string,end_at:string}>
     */
    public static function subtract(array $expected, array $covered): array
    {
        $result = [];

        foreach ($expected as $e) {
            [$es, $ee] = self::bounds($e);
            $pieces = [[$es, $ee]];

            foreach ($covered as $c) {
                [$cs, $ce] = self::bounds($c);
                $next = [];
                foreach ($pieces as [$ps, $pe]) {
                    // No overlap → keep the piece.
                    if ($ce <= $ps || $cs >= $pe) {
                        $next[] = [$ps, $pe];

                        continue;
                    }
                    // Left remainder.
                    if ($cs > $ps) {
                        $next[] = [$ps, min($cs, $pe)];
                    }
                    // Right remainder.
                    if ($ce < $pe) {
                        $next[] = [max($ce, $ps), $pe];
                    }
                }
                $pieces = $next;
            }

            foreach ($pieces as [$ps, $pe]) {
                if ($pe > $ps) {
                    $result[] = [
                        'start_at' => CarbonImmutable::createFromTimestampUTC($ps)->toIso8601String(),
                        'end_at' => CarbonImmutable::createFromTimestampUTC($pe)->toIso8601String(),
                    ];
                }
            }
        }

        return $result;
    }

    /** Total minutes across intervals. */
    public static function totalMinutes(array $intervals): int
    {
        $seconds = 0;
        foreach ($intervals as $i) {
            [$s, $e] = self::bounds($i);
            $seconds += max(0, $e - $s);
        }

        return intdiv($seconds, 60);
    }

    /** @return array{0:int,1:int} unix start, end */
    private static function bounds(array $interval): array
    {
        return [
            CarbonImmutable::parse($interval['start_at'])->getTimestamp(),
            CarbonImmutable::parse($interval['end_at'])->getTimestamp(),
        ];
    }
}
