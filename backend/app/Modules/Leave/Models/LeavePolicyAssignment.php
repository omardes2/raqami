<?php

namespace App\Modules\Leave\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Binds a policy to an organizational scope with an effective window + priority.
 * Resolved by LeavePolicyResolver (employee > team > department > branch >
 * company). Tenant-owned (tenant_id + RLS).
 */
class LeavePolicyAssignment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'leave_policy_id', 'leave_type_id',
        'scope_type', 'scope_id', 'effective_from', 'effective_until', 'priority',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_until' => 'date',
            'priority' => 'integer',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }
}
