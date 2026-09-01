<?php

namespace App\Modules\Attendance\Support;

/**
 * The SERVER's verdict on a GPS punch. The client sends coordinates (facts);
 * the server decides distance and whether the point is inside an approved
 * geofence — the client is never trusted to claim "I am at the office".
 */
final class GeofenceResult
{
    public function __construct(
        public readonly ?string $matchedLocationId,
        public readonly ?int $distanceMeters,
        public readonly bool $inside,
        public readonly bool $accuracyAcceptable,
    ) {}

    /** No coordinates were supplied at all. */
    public static function absent(): self
    {
        return new self(null, null, false, true);
    }
}
