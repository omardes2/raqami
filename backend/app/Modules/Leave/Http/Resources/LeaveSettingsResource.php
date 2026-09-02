<?php

namespace App\Modules\Leave\Http\Resources;

use App\Modules\Leave\Models\LeaveSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaveSetting */
class LeaveSettingsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'default_period_basis' => $this->default_period_basis?->value,
            'leave_year_start_month' => (int) $this->leave_year_start_month,
            'leave_year_start_day' => (int) $this->leave_year_start_day,
            'default_approval_flow' => $this->default_approval_flow?->value,
            'allow_withdrawal' => (bool) $this->allow_withdrawal,
            'allow_cancellation_request' => (bool) $this->allow_cancellation_request,
            'display_day_minutes' => (int) $this->display_day_minutes,
        ];
    }
}
