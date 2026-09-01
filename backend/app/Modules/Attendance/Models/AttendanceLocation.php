<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Organization\Models\Branch;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Approved geofence location (circular: center + radius, meters). Coordinates
 * stored as decimals to preserve precision. Tenant-owned (tenant_id + RLS).
 */
class AttendanceLocation extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'branch_id', 'name', 'latitude', 'longitude',
        'radius_meters', 'require_accuracy_meters', 'status', 'description',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'radius_meters' => 'integer',
            'require_accuracy_meters' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
