<?php

namespace App\Modules\Leave\Models;

use App\Modules\Leave\Enums\ConsumptionBasis;
use App\Modules\Leave\Enums\LeaveRequestKind;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-work_date snapshot of a request. Separates CONSUMPTION (balance) from
 * COVERAGE (attendance). coverage_intervals are UTC half-open [start, end).
 * Append-style (created_at only). Tenant-owned (tenant_id + RLS).
 */
class LeaveRequestDay extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'leave_request_id', 'employee_id', 'work_date', 'timezone',
        'scheduled_minutes', 'coverage_minutes', 'consumption_minutes', 'portion',
        'coverage_intervals', 'consumption_basis', 'nominal_day_minutes',
        'excluded_reason', 'holiday_id', 'holiday_snapshot', 'schedule_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'scheduled_minutes' => 'integer',
            'coverage_minutes' => 'integer',
            'consumption_minutes' => 'integer',
            'portion' => LeaveRequestKind::class,
            'coverage_intervals' => 'array',
            'consumption_basis' => ConsumptionBasis::class,
            'nominal_day_minutes' => 'integer',
            'holiday_snapshot' => 'array',
            'schedule_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}
