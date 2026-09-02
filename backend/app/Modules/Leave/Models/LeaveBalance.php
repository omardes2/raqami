<?php

namespace App\Modules\Leave\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Balance PROJECTION (cache + lock row). NOT authoritative — rebuildable from
 * the ledger. available = granted + accrued + carried + adjusted − used −
 * reserved − expired. Tenant-owned (tenant_id + RLS).
 */
class LeaveBalance extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'employee_id', 'leave_type_id', 'entitlement_period_id',
        'granted_minutes', 'accrued_minutes', 'carried_minutes', 'adjusted_minutes',
        'used_minutes', 'reserved_minutes', 'expired_minutes', 'available_minutes', 'version',
    ];

    protected function casts(): array
    {
        return [
            'granted_minutes' => 'integer',
            'accrued_minutes' => 'integer',
            'carried_minutes' => 'integer',
            'adjusted_minutes' => 'integer',
            'used_minutes' => 'integer',
            'reserved_minutes' => 'integer',
            'expired_minutes' => 'integer',
            'available_minutes' => 'integer',
            'version' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(LeaveEntitlementPeriod::class, 'entitlement_period_id');
    }
}
