<?php

namespace App\Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Sensitive: recurring component values. Company-level payroll authority only. */
class EmployeeCompensationComponentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'payroll_component_id' => $this->payroll_component_id,
            'fixed_amount_minor' => $this->fixed_amount_minor !== null ? (int) $this->fixed_amount_minor : null,
            'rate_bps' => $this->rate_bps !== null ? (int) $this->rate_bps : null,
            'currency' => $this->currency,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'version' => (int) $this->version,
        ];
    }
}
