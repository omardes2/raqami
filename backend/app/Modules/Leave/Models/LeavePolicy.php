<?php

namespace App\Modules\Leave\Models;

use App\Modules\Leave\Enums\AccrualFrequency;
use App\Modules\Leave\Enums\ApprovalFlow;
use App\Modules\Leave\Enums\ConsumptionBasis;
use App\Modules\Leave\Enums\EntitlementMethod;
use App\Modules\Leave\Enums\LeavePolicyStatus;
use App\Modules\Leave\Enums\PeriodType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configurable rule set bound to a leave type. Canonical unit is MINUTES.
 * Tenant-owned (tenant_id + RLS).
 */
class LeavePolicy extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'leave_type_id', 'name', 'status', 'effective_from', 'effective_until',
        'period_basis',
        'entitlement_method', 'entitlement_minutes',
        'accrual_frequency', 'accrual_minutes', 'proration_enabled',
        'max_balance_minutes', 'allow_negative_balance', 'max_negative_minutes',
        'carry_forward_enabled', 'carry_forward_max_minutes', 'carry_forward_expiry_days',
        'consumption_basis', 'nominal_day_minutes', 'count_holidays', 'count_non_working_days',
        'allow_half_day', 'minimum_request_minutes', 'maximum_request_minutes',
        'minimum_notice_days', 'maximum_advance_booking_days', 'requires_attachment',
        'allow_withdrawal', 'allow_cancellation_request',
        'approval_flow',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeavePolicyStatus::class,
            'effective_from' => 'date',
            'effective_until' => 'date',
            'period_basis' => PeriodType::class,
            'entitlement_method' => EntitlementMethod::class,
            'entitlement_minutes' => 'integer',
            'accrual_frequency' => AccrualFrequency::class,
            'accrual_minutes' => 'integer',
            'proration_enabled' => 'boolean',
            'max_balance_minutes' => 'integer',
            'allow_negative_balance' => 'boolean',
            'max_negative_minutes' => 'integer',
            'carry_forward_enabled' => 'boolean',
            'carry_forward_max_minutes' => 'integer',
            'carry_forward_expiry_days' => 'integer',
            'consumption_basis' => ConsumptionBasis::class,
            'nominal_day_minutes' => 'integer',
            'count_holidays' => 'boolean',
            'count_non_working_days' => 'boolean',
            'allow_half_day' => 'boolean',
            'minimum_request_minutes' => 'integer',
            'maximum_request_minutes' => 'integer',
            'minimum_notice_days' => 'integer',
            'maximum_advance_booking_days' => 'integer',
            'requires_attachment' => 'boolean',
            'allow_withdrawal' => 'boolean',
            'allow_cancellation_request' => 'boolean',
            'approval_flow' => ApprovalFlow::class,
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LeavePolicyAssignment::class);
    }
}
