<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Computed daily attendance state (one per employee per work_date). Schedule
 * boundaries + grace are SNAPSHOT here at check-in so later schedule edits do
 * not retro-alter history. Timestamps are UTC; the SERVER computes every minute
 * field — the client never sets them. Tenant-owned (tenant_id + RLS).
 */
class AttendanceRecord extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'employee_id', 'work_schedule_id', 'work_date', 'timezone',
        'scheduled_start_at', 'scheduled_end_at', 'check_in_at', 'check_out_at',
        'worked_minutes', 'break_minutes', 'late_minutes', 'early_leave_minutes',
        'overtime_minutes', 'grace_minutes', 'status', 'source',
        'check_in_latitude', 'check_in_longitude', 'check_in_inside_geofence', 'check_in_location_id',
        'check_out_latitude', 'check_out_longitude', 'check_out_inside_geofence', 'check_out_location_id',
        'is_manual', 'corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'source' => AttendanceSource::class,
            'work_date' => 'date',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'worked_minutes' => 'integer',
            'break_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'grace_minutes' => 'integer',
            'check_in_latitude' => 'decimal:7',
            'check_in_longitude' => 'decimal:7',
            'check_in_inside_geofence' => 'boolean',
            'check_out_latitude' => 'decimal:7',
            'check_out_longitude' => 'decimal:7',
            'check_out_inside_geofence' => 'boolean',
            'is_manual' => 'boolean',
            'corrected_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    public function isOpen(): bool
    {
        return $this->check_in_at !== null && $this->check_out_at === null;
    }
}
