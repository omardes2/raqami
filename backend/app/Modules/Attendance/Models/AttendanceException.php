<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\AttendanceMode;
use App\Modules\Attendance\Enums\ExceptionType;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Authorized temporary attendance exception. Tenant-owned (tenant_id + RLS). */
class AttendanceException extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'employee_id', 'type', 'effective_from', 'effective_until',
        'attendance_mode', 'alternate_schedule_id', 'alternate_location_id',
        'reason', 'status', 'approved_by_user_id', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => ExceptionType::class,
            'attendance_mode' => AttendanceMode::class,
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
