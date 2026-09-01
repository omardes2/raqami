<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable raw punch log (append-only). Records exactly what the client sent
 * and what the SERVER decided (geofence match, distance, accuracy). This is the
 * forensic trail behind attendance_records. Tenant-owned (tenant_id + RLS).
 */
class AttendanceEvent extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'employee_id', 'attendance_record_id', 'event_type', 'source',
        'occurred_at', 'latitude', 'longitude', 'accuracy_meters',
        'matched_location_id', 'distance_meters', 'inside_geofence',
        'metadata', 'created_by_user_id', 'client_request_id',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AttendanceEventType::class,
            'source' => AttendanceSource::class,
            'occurred_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_meters' => 'integer',
            'distance_meters' => 'integer',
            'inside_geofence' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function matchedLocation(): BelongsTo
    {
        return $this->belongsTo(AttendanceLocation::class, 'matched_location_id');
    }
}
