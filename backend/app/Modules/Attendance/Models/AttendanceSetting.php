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
        // Sprint 4
        'materialization_enabled', 'absence_materialize_after_minutes',
        'allow_multiple_sessions', 'auto_close_missing_checkout', 'auto_close_after_minutes',
        'overtime_requires_approval', 'overtime_auto_approve',
        'off_day_work_policy', 'default_attendance_mode',
        'anomaly_max_session_minutes', 'anomaly_gps_jump_meters',
        'anomaly_lateness_streak_days', 'anomaly_corrections_threshold',
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
            // Sprint 4
            'materialization_enabled' => 'boolean',
            'absence_materialize_after_minutes' => 'integer',
            'allow_multiple_sessions' => 'boolean',
            'auto_close_missing_checkout' => 'boolean',
            'auto_close_after_minutes' => 'integer',
            'overtime_requires_approval' => 'boolean',
            'overtime_auto_approve' => 'boolean',
            'anomaly_max_session_minutes' => 'integer',
            'anomaly_gps_jump_meters' => 'integer',
            'anomaly_lateness_streak_days' => 'integer',
            'anomaly_corrections_threshold' => 'integer',
        ];
    }
}
