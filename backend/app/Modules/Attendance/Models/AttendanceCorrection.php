<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Controlled correction workflow for an attendance_record: request -> approve/
 * reject, with no self-approval (enforced in the service). old_values snapshots
 * the record before an approved change is applied. Tenant-owned (tenant_id + RLS).
 */
class AttendanceCorrection extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'attendance_record_id', 'attendance_session_id', 'employee_id', 'requested_by_user_id',
        'requested_check_in_at', 'requested_check_out_at', 'reason', 'status',
        'reviewed_by_user_id', 'reviewed_at', 'rejection_reason',
        'old_values', 'new_values',
    ];

    protected function casts(): array
    {
        return [
            'status' => CorrectionStatus::class,
            'requested_check_in_at' => 'datetime',
            'requested_check_out_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
