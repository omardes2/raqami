<?php

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Enums\AttendanceSource;

/**
 * The FACTS a client submits with a punch. Deliberately minimal: coordinates,
 * accuracy, the channel, an optional idempotency key, and opaque device
 * metadata. The client never sends times, statuses, or "I am inside" — the
 * server derives every result.
 */
final class PunchInput
{
    public function __construct(
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?int $accuracyMeters = null,
        public readonly AttendanceSource $source = AttendanceSource::Web,
        public readonly ?string $clientRequestId = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, AttendanceSource $source = AttendanceSource::Web): self
    {
        return new self(
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            accuracyMeters: isset($data['accuracy_meters']) ? (int) $data['accuracy_meters'] : null,
            source: $source,
            clientRequestId: $data['client_request_id'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
