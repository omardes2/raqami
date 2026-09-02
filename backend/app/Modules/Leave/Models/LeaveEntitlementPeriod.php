<?php

namespace App\Modules\Leave\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\PeriodType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per (employee, leave_type) accounting window. The ledger + balance projection
 * are period-scoped. Tenant-owned (tenant_id + RLS).
 */
class LeaveEntitlementPeriod extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'employee_id', 'leave_type_id', 'leave_policy_id',
        'period_type', 'starts_on', 'ends_on', 'status',
    ];

    protected function casts(): array
    {
        return [
            'period_type' => PeriodType::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }
}
