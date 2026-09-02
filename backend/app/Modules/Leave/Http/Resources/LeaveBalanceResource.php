<?php

namespace App\Modules\Leave\Http\Resources;

use App\Modules\Leave\Models\LeaveBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaveBalance */
class LeaveBalanceResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'leave_type_id' => $this->leave_type_id,
            'entitlement_period_id' => $this->entitlement_period_id,
            'granted_minutes' => (int) $this->granted_minutes,
            'accrued_minutes' => (int) $this->accrued_minutes,
            'carried_minutes' => (int) $this->carried_minutes,
            'adjusted_minutes' => (int) $this->adjusted_minutes,
            'used_minutes' => (int) $this->used_minutes,
            'reserved_minutes' => (int) $this->reserved_minutes,
            'expired_minutes' => (int) $this->expired_minutes,
            'available_minutes' => (int) $this->available_minutes,
        ];
    }
}
