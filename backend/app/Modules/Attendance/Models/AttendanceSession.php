<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One check-in/out session. Multiple closed sessions may share a work_date
 * (split shifts); at most one open session per employee (partial unique index).
 * Server computes every minute field. Tenant-owned (tenant_id + RLS).
 */
class AttendanceSession extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'attendance_record_id', 'employee_id', 'sequence',
        'check_in_at', 'check_out_at', 'scheduled_start_at', 'scheduled_end_at',
        'worked_minutes', 'break_minutes', 'late_minutes', 'early_leave_minutes',
        'overtime_minutes', 'grace_minutes', 'source', 'is_manual',
        'check_in_latitude', 'check_in_longitude', 'check_in_inside_geofence', 'check_in_location_id',
        'check_out_latitude', 'check_out_longitude', 'check_out_inside_geofence', 'check_out_location_id',
    ];

    protected function casts(): array
    {
        return [
            'source' => AttendanceSource::class,
            'sequence' => 'integer',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'worked_minutes' => 'integer',
            'break_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'grace_minutes' => 'integer',
            'is_manual' => 'boolean',
            'check_in_latitude' => 'decimal:7',
            'check_in_longitude' => 'decimal:7',
            'check_in_inside_geofence' => 'boolean',
            'check_out_latitude' => 'decimal:7',
            'check_out_longitude' => 'decimal:7',
            'check_out_inside_geofence' => 'boolean',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isOpen(): bool
    {
        return $this->check_out_at === null;
    }
}
