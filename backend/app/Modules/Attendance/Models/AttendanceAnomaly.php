<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\AnomalyStatus;
use App\Modules\Attendance\Enums\AnomalyType;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A rule-based attendance anomaly (neutral language). Tenant-owned. */
class AttendanceAnomaly extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'employee_id', 'attendance_record_id', 'attendance_session_id',
        'type', 'severity', 'detected_at', 'status', 'metadata', 'dedupe_key',
        'resolved_at', 'resolved_by_user_id', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'type' => AnomalyType::class,
            'status' => AnomalyStatus::class,
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
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
}
