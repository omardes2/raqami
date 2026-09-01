<?php

namespace Tests\Unit\Attendance;

use App\Modules\Attendance\Models\AttendanceLocation;
use App\Modules\Attendance\Services\GeofenceService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Geofencing is a SERVER decision. The client sends coordinates; the server
 * computes the Haversine distance and decides inside/outside. These tests use
 * in-memory location models (no DB) to lock the pure geometry.
 */
class GeofenceServiceTest extends TestCase
{
    private function location(float $lat, float $lng, int $radius, ?int $requireAccuracy = null): AttendanceLocation
    {
        return (new AttendanceLocation)->forceFill([
            'id' => '01LOCATION0000000000000000',
            'latitude' => $lat,
            'longitude' => $lng,
            'radius_meters' => $radius,
            'require_accuracy_meters' => $requireAccuracy,
        ]);
    }

    public function test_haversine_known_distance_is_accurate(): void
    {
        // ~111.19 km per degree of latitude at the equator.
        $d = GeofenceService::haversineMeters(0.0, 0.0, 1.0, 0.0);

        $this->assertEqualsWithDelta(111_195, $d, 200);
    }

    public function test_point_inside_radius_is_inside(): void
    {
        $svc = new GeofenceService;
        $loc = $this->location(24.7136, 46.6753, 100); // Riyadh office

        // ~30m north of centre.
        $result = $svc->evaluate(24.71387, 46.6753, 10, new Collection([$loc]));

        $this->assertTrue($result->inside);
        $this->assertSame('01LOCATION0000000000000000', $result->matchedLocationId);
        $this->assertLessThanOrEqual(100, $result->distanceMeters);
    }

    public function test_point_outside_radius_is_outside(): void
    {
        $svc = new GeofenceService;
        $loc = $this->location(24.7136, 46.6753, 50);

        // ~330m away.
        $result = $svc->evaluate(24.7166, 46.6753, 10, new Collection([$loc]));

        $this->assertFalse($result->inside);
        $this->assertGreaterThan(50, $result->distanceMeters);
    }

    public function test_nearest_location_is_selected_among_many(): void
    {
        $svc = new GeofenceService;
        $far = (new AttendanceLocation)->forceFill([
            'id' => '01FAR0000000000000000000000',
            'latitude' => 25.0, 'longitude' => 46.0, 'radius_meters' => 100,
        ]);
        $near = $this->location(24.7136, 46.6753, 100);

        $result = $svc->evaluate(24.7137, 46.6753, 10, new Collection([$far, $near]));

        $this->assertSame('01LOCATION0000000000000000', $result->matchedLocationId);
        $this->assertTrue($result->inside);
    }

    public function test_missing_coordinates_returns_absent(): void
    {
        $svc = new GeofenceService;

        $result = $svc->evaluate(null, null, null, new Collection);

        $this->assertFalse($result->inside);
        $this->assertNull($result->matchedLocationId);
    }

    public function test_poor_accuracy_is_flagged_when_location_demands_precision(): void
    {
        $svc = new GeofenceService;
        $loc = $this->location(24.7136, 46.6753, 100, requireAccuracy: 20);

        $result = $svc->evaluate(24.7137, 46.6753, 80, new Collection([$loc])); // 80m accuracy > 20m required

        $this->assertFalse($result->accuracyAcceptable);
    }
}
