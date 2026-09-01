<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\WorkScheduleStatus;
use App\Modules\Attendance\Models\AttendanceLocation;
use App\Modules\Attendance\Support\GeofenceResult;
use Illuminate\Support\Collection;

/**
 * Server-authoritative geofencing. Given coordinates the client SENT, the server
 * computes the great-circle distance to every approved location and decides
 * whether the punch falls inside a geofence. Distance uses the Haversine formula
 * on a spherical-earth model — accurate to well within geofence radii.
 */
class GeofenceService
{
    /** Mean earth radius in metres (IUGG). */
    private const EARTH_RADIUS_M = 6_371_008.8;

    /**
     * Evaluate a punch against the tenant's active locations. Picks the nearest
     * location; "inside" is true when the point is within that location's radius.
     * Accuracy is rejected when the nearest location demands a tighter fix than
     * the device reported.
     *
     * @param  Collection<int, AttendanceLocation>|null  $locations  pre-scoped set (else all active)
     */
    public function evaluate(?float $latitude, ?float $longitude, ?int $accuracyMeters, ?Collection $locations = null): GeofenceResult
    {
        if ($latitude === null || $longitude === null) {
            return GeofenceResult::absent();
        }

        $locations ??= AttendanceLocation::query()
            ->where('status', WorkScheduleStatus::Active->value)
            ->get();

        if ($locations->isEmpty()) {
            return new GeofenceResult(null, null, false, true);
        }

        $nearest = null;
        $nearestDistance = null;

        foreach ($locations as $location) {
            $distance = self::haversineMeters(
                $latitude,
                $longitude,
                (float) $location->latitude,
                (float) $location->longitude,
            );

            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearest = $location;
            }
        }

        $distanceMeters = (int) round($nearestDistance);
        $inside = $distanceMeters <= (int) $nearest->radius_meters;

        $accuracyAcceptable = $nearest->require_accuracy_meters === null
            || $accuracyMeters === null
            || $accuracyMeters <= (int) $nearest->require_accuracy_meters;

        return new GeofenceResult(
            matchedLocationId: (string) $nearest->id,
            distanceMeters: $distanceMeters,
            inside: $inside,
            accuracyAcceptable: $accuracyAcceptable,
        );
    }

    /**
     * Great-circle distance between two lat/lng points in metres (Haversine).
     * Pure and deterministic — the core of the server-side geofence decision.
     */
    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2
            + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
