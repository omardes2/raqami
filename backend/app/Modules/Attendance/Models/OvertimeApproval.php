<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Overtime approval. calculated_minutes (raw, server-derived) is kept separate
 * from approved_minutes. Tenant-owned (tenant_id + RLS).
 */
class OvertimeApproval extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'attendance_record_id', 'employee_id', 'work_date',
        'calculated_minutes', 'approved_minutes', 'status',
        'reviewed_by_user_id', 'reviewed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => OvertimeStatus::class,
            'work_date' => 'date',
            'calculated_minutes' => 'integer',
            'approved_minutes' => 'integer',
            'reviewed_at' => 'datetime',
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
}
