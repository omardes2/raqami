<?php

namespace App\Modules\Leave\Http\Resources;

use App\Modules\Leave\Models\LeavePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeavePolicy */
class LeavePolicyResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'leave_type_id' => $this->leave_type_id,
            'name' => $this->name,
            'status' => $this->status?->value,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
            'period_basis' => $this->period_basis?->value,
            'entitlement_method' => $this->entitlement_method?->value,
            'entitlement_minutes' => (int) $this->entitlement_minutes,
            'accrual_frequency' => $this->accrual_frequency?->value,
            'accrual_minutes' => (int) $this->accrual_minutes,
            'proration_enabled' => (bool) $this->proration_enabled,
            'max_balance_minutes' => $this->max_balance_minutes,
            'allow_negative_balance' => (bool) $this->allow_negative_balance,
            'max_negative_minutes' => $this->max_negative_minutes,
            'carry_forward_enabled' => (bool) $this->carry_forward_enabled,
            'carry_forward_max_minutes' => $this->carry_forward_max_minutes,
            // carry_forward_expiry_days intentionally NOT exposed (reserved column;
            // carried-balance expiry-after-N-days is not implemented in Sprint 5).
            'consumption_basis' => $this->consumption_basis?->value,
            'nominal_day_minutes' => $this->nominal_day_minutes,
            'count_holidays' => (bool) $this->count_holidays,
            'count_non_working_days' => (bool) $this->count_non_working_days,
            'allow_half_day' => (bool) $this->allow_half_day,
            'minimum_request_minutes' => $this->minimum_request_minutes,
            'maximum_request_minutes' => $this->maximum_request_minutes,
            'minimum_notice_days' => $this->minimum_notice_days,
            'maximum_advance_booking_days' => $this->maximum_advance_booking_days,
            'requires_attachment' => (bool) $this->requires_attachment,
            'allow_withdrawal' => (bool) $this->allow_withdrawal,
            'allow_cancellation_request' => (bool) $this->allow_cancellation_request,
            'approval_flow' => $this->approval_flow?->value,
            'assignments' => $this->whenLoaded('assignments', fn () => $this->assignments->map(fn ($a) => [
                'id' => $a->id,
                'scope_type' => $a->scope_type,
                'scope_id' => $a->scope_id,
                'effective_from' => $a->effective_from?->toDateString(),
                'effective_until' => $a->effective_until?->toDateString(),
                'priority' => (int) $a->priority,
            ])),
        ];
    }
}
