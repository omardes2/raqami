<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant attendance policy (one row per tenant). Tenant-owned (tenant_id + RLS).
 */
class AttendanceSetting extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'default_timezone', 'default_grace_minutes',
        'geofence_required', 'require_gps', 'min_gps_accuracy_meters',
        'allow_early_check_in', 'early_check_in_window_minutes', 'allow_late_check_in',
        'overtime_tracking_enabled', 'overtime_after_minutes',
        'attendance_correction_enabled', 'allow_employee_correction_request',
        'allow_unscheduled_work',
    ];

    protected function casts(): array
    {
        return [
            'default_grace_minutes' => 'integer',
            'geofence_required' => 'boolean',
            'require_gps' => 'boolean',
            'min_gps_accuracy_meters' => 'integer',
            'allow_early_check_in' => 'boolean',
            'early_check_in_window_minutes' => 'integer',
            'allow_late_check_in' => 'boolean',
            'overtime_tracking_enabled' => 'boolean',
            'overtime_after_minutes' => 'integer',
            'attendance_correction_enabled' => 'boolean',
            'allow_employee_correction_request' => 'boolean',
            'allow_unscheduled_work' => 'boolean',
        ];
    }
}
