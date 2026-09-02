<?php

namespace App\Modules\Attendance\Enums;

/** Rule-based anomaly kinds. Neutral language — never asserts fraud. */
enum AnomalyType: string
{
    case MissingCheckout = 'missing_checkout';
    case LongSession = 'long_session';
    case SuspiciousLocationChange = 'suspicious_location_change';
    case OutsideGeofence = 'outside_geofence';
    case OverlappingSessions = 'overlapping_sessions';
    case LatenessStreak = 'lateness_streak';
    case ExcessiveCorrections = 'excessive_corrections';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
